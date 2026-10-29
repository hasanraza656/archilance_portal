<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Stripe\StripeClient;

/**
 * Looks in Stripe for subscriptions that belong to the signed-in customer, found by their LOGIN EMAIL (the portal keeps no
 * other link for customers who subscribed outside it), and turns them into what the dashboard shows: the plan, how far
 * through the billing period they are, what is due and when.
 *
 * Read-only: nothing is ever created, changed or charged here. A customer only ever sees Stripe customers whose email
 * equals their own (plus a Stripe customer the portal itself stored for them); if there is none, `matched` is false and
 * the dashboard shows nothing about subscriptions.
 *
 * Speed matters (Stripe is a network call each time): one request per email variant returns the customers WITH their
 * subscriptions and each subscription's latest invoice, so a typical lookup is 1-3 calls instead of dozens. The recent
 * invoice list is a separate, on-demand call (recentInvoices()).
 */
class CustomerSubscriptionSummary
{
    private const LIVE = ['active', 'trialing', 'past_due', 'unpaid', 'incomplete', 'paused'];
    private const ZERO_DECIMAL = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

    private ?StripeClient $client = null;

    public function client(): ?StripeClient
    {
        if ($this->client) {
            return $this->client;
        }
        $secret = self::secret();
        if ($secret === '') {
            return null;
        }

        return $this->client = new StripeClient($secret);
    }

    /**
     * The Stripe secret key. When the host has cached the configuration (php artisan config:cache, or a cached
     * bootstrap/cache/config.php), env() returns nothing outside the config files, so the key is read from the config,
     * then from env(), and finally straight from the project's .env file, so an uploaded .env is always honoured.
     */
    public static function secret(?string $basePath = null): string
    {
        foreach ([config('services.stripe.secret'), env('STRIPE_SECRET')] as $v) {
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        try {
            $file = rtrim($basePath ?? base_path(), '/\\') . DIRECTORY_SEPARATOR . '.env';
            if (is_readable($file)) {
                $vars = \Dotenv\Dotenv::parse((string) file_get_contents($file));
                $v = $vars['STRIPE_SECRET'] ?? '';

                return is_string($v) ? trim($v) : '';
            }
        } catch (\Throwable $e) {
            // fall through: not configured
        }

        return '';
    }

    public function useClient(StripeClient $client): self
    {
        $this->client = $client;

        return $this;
    }

    private function money($minor, $currency): float
    {
        $div = in_array(strtolower((string) $currency), self::ZERO_DECIMAL, true) ? 1 : 100;

        return round(((float) $minor) / $div, 2);
    }

    private function iso($ts): ?string
    {
        return $ts ? Carbon::createFromTimestamp((int) $ts)->toIso8601String() : null;
    }

    /**
     * Stripe customers whose email is this user's (exact, then lower-case: Stripe's email filter is case-sensitive),
     * with their current subscriptions and each one's latest invoice, plus the customer the portal stored, if any.
     *
     * @return array<string,object> customer id => customer
     */
    private function customers(StripeClient $stripe, User $user): array
    {
        $email = trim((string) $user->email);
        if ($email === '') {
            return [];
        }

        $found = [];
        $expand = ['data.subscriptions.data.latest_invoice'];

        foreach (array_unique([$email, strtolower($email)]) as $e) {
            foreach ($stripe->customers->all(['email' => $e, 'limit' => 20, 'expand' => $expand])->data as $c) {
                if (strtolower((string) ($c->email ?? '')) === strtolower($email) && empty($c->deleted)) {
                    $found[$c->id] = $c;
                }
            }
        }

        try {
            $own = Subscription::where('user_id', $user->id)->whereNotNull('stripe_customer_id')->pluck('stripe_customer_id')->unique()->take(3);
            foreach ($own as $id) {
                if (!isset($found[$id])) {
                    $c = $stripe->customers->retrieve($id, ['expand' => ['subscriptions.data.latest_invoice']]);
                    if (empty($c->deleted)) {
                        $found[$c->id] = $c;
                    }
                }
            }
        } catch (\Throwable $e) {
            // optional extra
        }

        return $found;
    }

    private function productName($price): string
    {
        if (!empty($price->nickname)) {
            return (string) $price->nickname;
        }
        $product = $price->product ?? null;
        if (is_object($product) && !empty($product->name)) {
            return (string) $product->name;
        }
        $pid = is_object($product) ? ($product->id ?? null) : $product;
        if (!$pid) {
            return 'Subscription';
        }

        // Product names hardly ever change: remember them for a day, so this is not a Stripe call on most visits.
        return Cache::remember('stripe-product-name:' . $pid, now()->addDay(), function () use ($pid) {
            try {
                return (string) ($this->client()->products->retrieve($pid)->name ?? '') ?: 'Subscription';
            } catch (\Throwable $e) {
                return 'Subscription';
            }
        });
    }

    /** @return array<string,mixed> */
    public function forUser(User $user): array
    {
        $stripe = $this->client();
        if (!$stripe) {
            return ['matched' => false, 'reason' => 'not_configured'];
        }

        $customers = $this->customers($stripe, $user);
        if (!$customers) {
            return ['matched' => false, 'reason' => 'no_match'];
        }

        $subs = [];
        $open = [];
        $liveCustomerIds = [];

        foreach ($customers as $cid => $c) {
            foreach (($c->subscriptions->data ?? []) as $s) {
                if (!in_array($s->status, self::LIVE, true)) {
                    continue;
                }
                $subs[] = $this->presentSubscription($s);
                $liveCustomerIds[$cid] = true;

                $inv = $s->latest_invoice ?? null;
                if (is_object($inv) && ($inv->status ?? null) === 'open') {
                    $cur = strtoupper((string) $inv->currency);
                    $open[] = [
                        'id' => $inv->id,
                        'number' => $inv->number ?? null,
                        'amount_due' => $this->money($inv->amount_remaining ?? 0, $cur),
                        'currency' => $cur,
                        'due_date' => $this->iso($inv->due_date ?? null),
                        'url' => $inv->hosted_invoice_url ?? null,
                    ];
                }
            }
        }

        // Nothing current: say when the last plan ended (one small call).
        $ended = null;
        if (!$subs) {
            try {
                $first = array_key_first($customers);
                $old = $stripe->subscriptions->all(['customer' => $first, 'status' => 'canceled', 'limit' => 3])->data;
                usort($old, fn ($a, $b) => ($b->ended_at ?? 0) <=> ($a->ended_at ?? 0));
                if ($old) {
                    $ended = ['plan' => $this->productName($old[0]->items->data[0]->price ?? null), 'ended_at' => $this->iso($old[0]->ended_at ?? $old[0]->canceled_at ?? null)];
                }
            } catch (\Throwable $e) {
                // optional
            }
        }

        $outstanding = [];
        foreach ($open as $o) {
            $c = $o['currency'];
            $outstanding[$c] ??= ['currency' => $c, 'total' => 0.0, 'count' => 0, 'next_due' => null];
            $outstanding[$c]['total'] = round($outstanding[$c]['total'] + $o['amount_due'], 2);
            $outstanding[$c]['count']++;
            if ($o['due_date'] && (!$outstanding[$c]['next_due'] || $o['due_date'] < $outstanding[$c]['next_due'])) {
                $outstanding[$c]['next_due'] = $o['due_date'];
            }
        }

        // Remembered for the on-demand invoice list.
        Cache::put('customer-sub-customers:' . $user->id, array_keys($liveCustomerIds ?: $customers), now()->addHours(6));

        return [
            'matched' => true,
            'subscriptions' => $subs,
            'ended' => $ended,
            'outstanding' => array_values($outstanding),
            'open_invoices' => array_slice($open, 0, 5),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /** Latest invoices and the total paid, fetched only when the customer opens the list. */
    public function recentInvoices(User $user): array
    {
        $stripe = $this->client();
        $ids = Cache::get('customer-sub-customers:' . $user->id, []);
        if (!$stripe || !$ids) {
            return ['invoices' => [], 'paid_total' => []];
        }

        $rows = [];
        $paid = [];
        foreach (array_slice($ids, 0, 4) as $cid) {
            foreach ($stripe->invoices->all(['customer' => $cid, 'limit' => 6])->data as $inv) {
                $cur = strtoupper((string) $inv->currency);
                $rows[] = [
                    'id' => $inv->id,
                    'number' => $inv->number ?? null,
                    'status' => $inv->status,
                    'amount' => $this->money($inv->total ?? 0, $cur),
                    'currency' => $cur,
                    'created' => $this->iso($inv->created ?? null),
                    'url' => $inv->hosted_invoice_url ?? null,
                    'pdf' => $inv->invoice_pdf ?? null,
                ];
                if ($inv->status === 'paid') {
                    $paid[$cur] = ($paid[$cur] ?? 0) + $this->money($inv->amount_paid ?? 0, $cur);
                }
            }
        }
        usort($rows, fn ($a, $b) => strcmp((string) $b['created'], (string) $a['created']));

        return [
            'invoices' => array_slice($rows, 0, 8),
            'paid_total' => array_map(fn ($c, $t) => ['currency' => $c, 'total' => round($t, 2)], array_keys($paid), array_values($paid)),
        ];
    }

    private function presentSubscription($s): array
    {
        $item = $s->items->data[0] ?? null;
        $price = $item->price ?? null;
        $cur = strtolower((string) ($price->currency ?? $s->currency ?? 'usd'));
        $qty = (int) ($item->quantity ?? 1) ?: 1;

        $start = $s->current_period_start ?? ($item->current_period_start ?? null);
        $end = $s->current_period_end ?? ($item->current_period_end ?? null);
        $now = time();
        $pct = ($start && $end && $end > $start) ? max(0, min(100, (int) round(($now - $start) / ($end - $start) * 100))) : null;
        $daysLeft = $end ? max(0, (int) ceil(($end - $now) / 86400)) : null;
        $daysTotal = ($start && $end) ? max(1, (int) round(($end - $start) / 86400)) : null;

        // The next payment is the plan price for the quantity (taxes, if any, are added by Stripe on the invoice).
        $next = null;
        if ($price && isset($price->unit_amount) && empty($s->cancel_at_period_end)) {
            $next = ['amount' => $this->money($price->unit_amount * $qty, $cur), 'currency' => strtoupper($cur), 'date' => $this->iso($end)];
        }

        return [
            'id' => $s->id,
            'status' => $s->status,
            'plan' => $price ? $this->productName($price) : 'Subscription',
            'amount' => $price && isset($price->unit_amount) ? $this->money($price->unit_amount * $qty, $cur) : null,
            'currency' => strtoupper($cur),
            'interval' => $price->recurring->interval ?? null,
            'interval_count' => (int) ($price->recurring->interval_count ?? 1),
            'quantity' => $qty,
            'period_start' => $this->iso($start),
            'period_end' => $this->iso($end),
            'progress_pct' => $pct,
            'days_left' => $daysLeft,
            'days_total' => $daysTotal,
            'cancel_at_period_end' => (bool) ($s->cancel_at_period_end ?? false),
            'cancel_at' => $this->iso($s->cancel_at ?? null),
            'trial_end' => $this->iso($s->trial_end ?? null),
            'started' => $this->iso($s->start_date ?? $s->created ?? null),
            'next_invoice' => $next,
        ];
    }
}
