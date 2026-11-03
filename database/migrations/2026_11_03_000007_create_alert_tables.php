<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20); // email, slack, telegram, webhook
            $table->text('config')->comment('JSON chiffré');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 40); // voir App\Alerts\AlertType
            $table->json('params')->nullable();
            $table->string('severity', 10)->default('warning'); // info, warning, critical
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('cooldown_minutes')->default(720)->comment('délai minimal entre deux notifications');
            $table->boolean('notify_resolved')->default(true);
            $table->boolean('active')->default(false)->comment('condition actuellement vraie');
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('alert_rule_channel', function (Blueprint $table) {
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_channel_id')->constrained()->cascadeOnDelete();
            $table->primary(['alert_rule_id', 'notification_channel_id']);
        });

        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->string('severity', 10);
            $table->string('title');
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->json('deliveries')->nullable();
            $table->timestamps();
            $table->index(['type', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
        Schema::dropIfExists('alert_rule_channel');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('notification_channels');
    }
};
