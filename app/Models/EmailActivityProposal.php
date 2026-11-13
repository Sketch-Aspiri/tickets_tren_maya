<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailProposalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Propuesta de actividad de la IA sobre un correo entrante (decision 94). Se escribe SIEMPRE desde
 * `EmailActivityService` (forceFill), nunca por mass-assignment: por eso no tiene `$fillable`.
 */
final class EmailActivityProposal extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'incoming_email_id' => 'integer',
            'mcp_client_id' => 'integer',
            'responsible_id' => 'integer',
            'team_id' => 'integer',
            'due_date' => 'date',
            'confidence' => 'integer',
            'status' => EmailProposalStatus::class,
            'unchanged' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<IncomingEmail, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(IncomingEmail::class, 'incoming_email_id');
    }

    /**
     * @return BelongsTo<McpClient, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(McpClient::class, 'mcp_client_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
