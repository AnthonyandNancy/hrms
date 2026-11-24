<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 64);
            $table->string('name', 128);
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('DRAFT');
            $table->json('definition_json');
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'code', 'version']);
            $table->index(['tenant_id', 'status', 'code']);
        });

        Schema::create('workflow_template_nodes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('template_id');
            $table->string('key', 64);
            $table->string('name', 128);
            $table->string('type', 16);
            $table->unsignedInteger('sequence');
            $table->json('assignee_rule_json')->nullable();
            $table->json('condition_json')->nullable();
            $table->unsignedInteger('required_count')->default(1);
            $table->unsignedInteger('sla_hours')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['template_id', 'key']);
            $table->index(['template_id', 'sequence']);
        });

        Schema::create('cases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('case_no', 40);
            $table->string('type', 64);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('title', 255);
            $table->json('payload_json');
            $table->string('status', 16);
            $table->string('effect_status', 16)->default('NONE');
            $table->unsignedBigInteger('requester_user_id');
            $table->unsignedBigInteger('template_id')->nullable();
            $table->unsignedInteger('template_version');
            $table->json('template_snapshot_json')->nullable();
            $table->date('business_date');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'case_no']);
            $table->index(['tenant_id', 'status', 'business_date']);
            $table->index(['tenant_id', 'effect_status']);
            $table->index(['tenant_id', 'requester_user_id']);
            $table->index(['tenant_id', 'type', 'business_date']);
        });

        Schema::create('workflow_instances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('case_id');
            $table->string('current_node_key', 64)->nullable();
            $table->string('status', 16);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();

            $table->unique('case_id');
        });

        Schema::create('workflow_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('case_id');
            $table->string('node_key', 64);
            $table->unsignedBigInteger('assignee_user_id');
            $table->string('status', 16);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['case_id', 'node_key', 'assignee_user_id']);
            $table->index(['tenant_id', 'assignee_user_id', 'status', 'due_at']);
        });

        Schema::create('decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('case_id');
            $table->string('node_key', 64);
            $table->unsignedBigInteger('actor_user_id');
            $table->unsignedInteger('decision_seq');
            $table->string('action', 16);
            $table->text('comment')->nullable();
            $table->json('actor_snapshot_json');
            $table->unsignedInteger('template_version');
            $table->string('evidence_hash', 64)->nullable();
            $table->timestamp('decided_at');
            $table->string('request_id', 64)->nullable();

            // Duplicate/concurrent approvals collide here even if application
            // level row locking is bypassed by an unusual driver.
            $table->unique(['case_id', 'node_key', 'actor_user_id', 'decision_seq'], 'decision_actor_seq_unique');
            $table->index(['tenant_id', 'case_id', 'decided_at']);
        });

        Schema::create('case_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('case_id');
            $table->string('event_type', 64);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->json('payload_json');
            $table->timestamp('occurred_at');

            $table->index(['case_id', 'occurred_at']);
            $table->index(['tenant_id', 'case_id']);
        });

        Schema::create('effects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('decision_id')->nullable();
            $table->string('effect_type', 64);
            $table->string('aggregate_type', 64);
            $table->string('aggregate_id', 64);
            $table->unsignedInteger('effect_seq');
            $table->string('status', 16);
            $table->date('scheduled_for')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->json('payload_json')->nullable();
            $table->boolean('requires_escalation')->default(false);
            $table->timestamps();

            // The domain idempotency key. One effect sequence per aggregate for
            // a given effect type can only ever be applied once.
            $table->unique(['effect_type', 'aggregate_id', 'effect_seq'], 'effect_aggregate_seq_unique');
            $table->index(['tenant_id', 'case_id']);
            $table->index(['tenant_id', 'status', 'scheduled_for']);
        });

        Schema::create('case_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 16);
            $table->timestamp('created_at')->nullable();

            $table->unique(['case_id', 'user_id', 'role']);
            $table->index(['user_id', 'role']);
        });

        Schema::create('workflow_delegations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('proxy_user_id');
            $table->unsignedBigInteger('principal_user_id');
            $table->date('valid_from');
            $table->date('valid_to');
            $table->unsignedBigInteger('max_amount_minor')->nullable();
            $table->json('scope_json')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            // Explicit short name. The auto-generated name is 76 characters
            // (workflow_delegations_tenant_id_proxy_user_id_valid_from_valid_to_index)
            // and MySQL caps identifiers at 64, so the default fails on MySQL
            // while passing on SQLite. This is a defect fix, not a schema change:
            // the indexed columns and semantics are identical.
            $table->index(['tenant_id', 'proxy_user_id', 'valid_from', 'valid_to'], 'workflow_deleg_proxy_window_idx');
            $table->index(['tenant_id', 'principal_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_delegations');
        Schema::dropIfExists('case_members');
        Schema::dropIfExists('effects');
        Schema::dropIfExists('case_events');
        Schema::dropIfExists('decisions');
        Schema::dropIfExists('workflow_tasks');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('cases');
        Schema::dropIfExists('workflow_template_nodes');
        Schema::dropIfExists('workflow_templates');
    }
};
