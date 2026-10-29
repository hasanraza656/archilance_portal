<?php

namespace Tests\Unit;

use App\Http\Controllers\API\customer\ProjectTaskController;
use App\Models\ProjectTask;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Exercises the real controller against SQLite, without booting the production DB provider. */
class CustomerTaskWritesTest extends TestCase
{
    private Capsule $db;
    private Application $app;
    private object $actor;

    protected function setUp(): void
    {
        $this->app = new Application(dirname(__DIR__, 2));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        if (!class_exists('Auth')) {
            class_alias(\Illuminate\Support\Facades\Auth::class, 'Auth');
        }
        $this->db = new Capsule($this->app);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        require_once dirname(__DIR__, 2) . '/app/Helpers/helpers.php';

        $schema = $this->db->schema();
        $schema->create('users', function ($t) {
            $t->id();
            $t->softDeletes();
        });
        $schema->create('projects', function ($t) {
            $t->id();
            $t->unsignedBigInteger('customer_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });
        $schema->create('project_tasks', function ($t) {
            $t->id();
            foreach (['project_id', 'parent_task_id', 'created_by'] as $column) {
                $t->unsignedBigInteger($column)->nullable();
            }
            foreach (['task_title', 'task_description', 'task_status', 'priority', 'due_date'] as $column) {
                $t->string($column)->nullable();
            }
            $t->timestamps();
        });
        $schema->create('task_assignees', function ($t) {
            $t->id();
            $t->unsignedBigInteger('task_id');
            $t->unsignedBigInteger('employee_id');
            $t->timestamps();
        });
        $schema->create('task_attachments', function ($t) {
            $t->id();
            $t->unsignedBigInteger('task_id');
            $t->unsignedBigInteger('user_id');
            foreach (['file_path', 'file_type', 'file_name'] as $column) {
                $t->string($column)->nullable();
            }
            $t->integer('file_size')->nullable();
            $t->timestamps();
        });
        $this->db->table('projects')->insert([
            ['id' => 1, 'customer_id' => 10],
            ['id' => 2, 'customer_id' => 20],
        ]);
        $this->actor = (object) ['id' => 10, 'user_role' => 4];
        $guard = $this->createMock(\Illuminate\Contracts\Auth\Guard::class);
        $guard->method('user')->willReturn($this->actor);
        $guard->method('id')->willReturn($this->actor->id);
        $this->app->instance('auth', $guard);

        $validator = new Factory(new Translator(new ArrayLoader(), 'en'), $this->app);
        $validator->setPresenceVerifier(new DatabasePresenceVerifier($this->db->getDatabaseManager()));
        Request::macro('validate', function ($rules) use ($validator) {
            return $validator->make($this->all(), $rules)->validate();
        });
        $responses = $this->createMock(ResponseFactory::class);
        $responses->method('json')->willReturnCallback(fn ($data = [], $status = 200) => new JsonResponse($data, $status));
        $this->app->instance(ResponseFactory::class, $responses);
    }

    protected function tearDown(): void
    {
        $this->db->getConnection()->disconnect();
        Request::flushMacros();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    private function createTask(int $projectId, ?int $parentId = null): JsonResponse
    {
        return (new ProjectTaskController())->store(Request::create('/', 'POST', [
            'project_id' => $projectId, 'parent_task_id' => $parentId,
            'task_title' => 'Customer task', 'task_status' => 'Backlog',
        ]));
    }

    public function test_customer_can_create_a_task_and_subtask_in_own_job(): void
    {
        $task = $this->createTask(1)->getData()->task;
        $child = $this->createTask(1, $task->id)->getData()->task;
        $this->assertSame(10, $task->created_by);
        $this->assertSame($task->id, $child->parent_task_id);
        $this->assertSame(2, ProjectTask::count());
    }

    public function test_customer_cannot_create_in_another_customers_job(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->createTask(2);
    }

    public function test_customer_cannot_attach_a_task_to_a_parent_in_another_job(): void
    {
        $foreign = ProjectTask::create(['project_id' => 2, 'task_title' => 'Foreign']);
        $this->expectException(ModelNotFoundException::class);
        $this->createTask(1, $foreign->id);
    }

    public function test_customer_can_delete_a_task_in_own_job(): void
    {
        $task = $this->createTask(1)->getData()->task;
        $response = (new ProjectTaskController())->destroy($task->id);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(ProjectTask::find($task->id));
    }

    public function test_customer_cannot_delete_another_customers_task(): void
    {
        $task = ProjectTask::create(['project_id' => 2, 'task_title' => 'Foreign']);
        try {
            (new ProjectTaskController())->destroy($task->id);
            $this->fail('Foreign task deletion should be refused.');
        } catch (ModelNotFoundException $e) {
            $this->assertNotNull(ProjectTask::find($task->id));
        }
    }

    public function test_customer_team_member_does_not_gain_delete_access(): void
    {
        $task = $this->createTask(1)->getData()->task;
        $this->actor->user_role = 5;
        try {
            (new ProjectTaskController())->destroy($task->id);
            $this->fail('Member deletion should be refused.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertNotNull(ProjectTask::find($task->id));
        }
    }

    public function test_customer_can_edit_task_fields_without_overwriting_omitted_values(): void
    {
        $task = ProjectTask::create([
            'project_id' => 1, 'task_title' => 'Original', 'task_description' => 'Keep this',
            'task_status' => 'In Progress', 'priority' => 'Normal', 'due_date' => '2026-12-10',
        ]);
        $controller = new ProjectTaskController();
        foreach (['task_title' => 'Renamed', 'task_description' => '<p>Updated brief</p>', 'priority' => 'Urgent'] as $key => $value) {
            $response = $controller->update(Request::create('/', 'PUT', [$key => $value]), $task->id);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame($value, $task->fresh()->getAttribute($key));
            $this->assertSame('In Progress', $task->fresh()->task_status);
            $this->assertSame('2026-12-10', $task->fresh()->due_date);
        }
        $this->assertSame('Renamed', $task->fresh()->task_title);
    }

    public function test_customer_can_change_status_and_clear_due_date(): void
    {
        $task = ProjectTask::create([
            'project_id' => 1, 'task_title' => 'Original',
            'task_status' => 'Backlog', 'due_date' => '2026-12-10',
        ]);
        (new ProjectTaskController())->update(Request::create('/', 'PUT', [
            'task_status' => 'Completed', 'due_date' => null,
        ]), $task->id);
        $this->assertSame('Completed', $task->fresh()->task_status);
        $this->assertNull($task->fresh()->due_date);
        $this->assertSame('Completed', $this->db->table('projects')->where('id', 1)->value('status'));
    }

    public function test_customer_cannot_edit_a_foreign_task_or_move_an_owned_task(): void
    {
        $owned = ProjectTask::create(['project_id' => 1, 'task_title' => 'Own', 'task_status' => 'Backlog']);
        (new ProjectTaskController())->update(Request::create('/', 'PUT', [
            'task_title' => 'Renamed', 'project_id' => 2, 'created_by' => 20, 'parent_task_id' => 999,
        ]), $owned->id);
        $this->assertSame(1, $owned->fresh()->project_id);
        $this->assertNull($owned->fresh()->created_by);
        $this->assertNull($owned->fresh()->parent_task_id);

        $foreign = ProjectTask::create(['project_id' => 2, 'task_title' => 'Foreign']);
        try {
            (new ProjectTaskController())->update(Request::create('/', 'PUT', ['task_title' => 'Changed']), $foreign->id);
            $this->fail('Foreign task editing should be refused.');
        } catch (ModelNotFoundException $e) {
            $this->assertSame('Foreign', $foreign->fresh()->task_title);
        }
    }

    public function test_customer_can_upload_and_remove_task_attachments(): void
    {
        $task = ProjectTask::create(['project_id' => 1, 'task_title' => 'Own', 'task_status' => 'Backlog']);
        $storage = $this->getMockBuilder(\App\Services\OneDriveService::class)->disableOriginalConstructor()->onlyMethods(['upload', 'delete'])->getMock();
        $storage->expects($this->once())->method('upload');
        $storage->expects($this->once())->method('delete');
        $this->app->instance(\App\Services\OneDriveService::class, $storage);
        $request = Request::create('/', 'POST', [], [], [
            'attachments' => [\Illuminate\Http\UploadedFile::fake()->create('brief.pdf', 1, 'application/pdf')],
        ]);
        $controller = new ProjectTaskController();
        $response = $controller->update($request, $task->id);
        $attachments = $response->getData()->task->attachments;
        $this->assertCount(1, $attachments);
        $controller->update(Request::create('/', 'POST', ['delete_attachments' => [$attachments[0]->id]]), $task->id);
        $this->assertSame(0, $task->attachments()->count());
    }

    public function test_customer_team_member_does_not_gain_edit_access(): void
    {
        $task = ProjectTask::create(['project_id' => 1, 'task_title' => 'Own']);
        $this->actor->user_role = 5;
        try {
            (new ProjectTaskController())->update(Request::create('/', 'PUT', ['task_title' => 'Changed']), $task->id);
            $this->fail('Member editing should be refused.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Own', $task->fresh()->task_title);
        }
    }
}
