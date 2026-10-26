<?php

namespace App\Services\Mcp;

/** Raised when a risky action was asked for without confirm=true. The message is the plain-language preview. */
class NeedsConfirm extends ToolError
{
}
