<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------- areas
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();
        });

        // ---------------------------------------------------------- checkpoints
        Schema::create('checkpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('area_id');
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->unsignedInteger('radius_meter')->default(30);
            $table->string('qr_token', 100)->unique();
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('area_id')->references('id')->on('areas');
            $table->index('area_id');
            $table->index('status');
        });

        // ---------------------------------------------------------- patrol_routes
        Schema::create('patrol_routes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('area_id');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->enum('route_type', ['SEQUENTIAL', 'FLEXIBLE'])->default('SEQUENTIAL');
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('area_id')->references('id')->on('areas');
            $table->index('area_id');
        });

        // ------------------------------------------------------ route_checkpoints
        Schema::create('route_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('route_id');
            $table->unsignedBigInteger('checkpoint_id');
            $table->unsignedInteger('sequence')->default(1);
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->foreign('route_id')->references('id')->on('patrol_routes')->cascadeOnDelete();
            $table->foreign('checkpoint_id')->references('id')->on('checkpoints');
            $table->unique(['route_id', 'checkpoint_id']);
            $table->index(['route_id', 'sequence']);
        });

        // ------------------------------------------------------ patrol_schedules
        Schema::create('patrol_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('route_id');
            $table->string('name', 150);
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 0=Sunday..6=Saturday; null = daily
            $table->time('start_time');
            $table->time('end_time');
            $table->integer('grace_before_minutes')->default(15);
            $table->integer('grace_after_minutes')->default(15);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();

            $table->foreign('route_id')->references('id')->on('patrol_routes');
            $table->index(['day_of_week', 'start_time']);
        });

        // ------------------------------------------- patrol_schedule_assignments
        Schema::create('patrol_schedule_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->foreign('schedule_id')->references('id')->on('patrol_schedules')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
            $table->unique(['schedule_id', 'user_id']);
        });

        // ---------------------------------------------------------------- devices
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('device_uuid', 150)->unique();
            $table->string('device_name', 150)->nullable();
            $table->string('platform', 30)->nullable();
            $table->string('app_version', 30)->nullable();
            $table->decimal('last_latitude', 10, 8)->nullable();
            $table->decimal('last_longitude', 11, 8)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->enum('status', ['ACTIVE', 'BLOCKED'])->default('ACTIVE');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users');
            $table->index('user_id');
        });

        // -------------------------------------------------------- patrol_sessions
        Schema::create('patrol_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('session_code', 50)->unique();
            $table->unsignedBigInteger('schedule_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('route_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->decimal('started_latitude', 10, 8)->nullable();
            $table->decimal('started_longitude', 11, 8)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->decimal('completed_latitude', 10, 8)->nullable();
            $table->decimal('completed_longitude', 11, 8)->nullable();
            $table->enum('status', ['RUNNING', 'COMPLETED', 'INCOMPLETE', 'CANCELLED'])->default('RUNNING');
            $table->unsignedInteger('total_checkpoint')->default(0);
            $table->unsignedInteger('completed_checkpoint')->default(0);
            $table->timestamps();

            $table->foreign('schedule_id')->references('id')->on('patrol_schedules');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('route_id')->references('id')->on('patrol_routes');
            $table->foreign('device_id')->references('id')->on('devices');
            $table->index('user_id');
            $table->index('status');
            $table->index('started_at');
        });

        // -------------------------------------------------------- patrol_checkins
        Schema::create('patrol_checkins', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('checkpoint_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('scan_code', 100)->nullable();
            $table->dateTime('scanned_at');
            $table->dateTime('device_timestamp')->nullable();
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->decimal('distance_meter', 10, 2)->nullable();
            $table->decimal('gps_accuracy', 10, 2)->nullable();
            $table->enum('validation_status', [
                'VALID', 'INVALID_LOCATION', 'INVALID_CHECKPOINT',
                'DUPLICATE', 'INVALID_SESSION', 'INVALID_SEQUENCE',
            ]);
            $table->enum('sync_status', ['SYNCED', 'PENDING', 'FAILED'])->default('SYNCED');
            $table->timestamps();

            $table->foreign('session_id')->references('id')->on('patrol_sessions');
            $table->foreign('checkpoint_id')->references('id')->on('checkpoints');
            $table->foreign('device_id')->references('id')->on('devices');
            $table->index('session_id');
            $table->index('checkpoint_id');
            $table->index('scanned_at');
        });

        // ---------------------------------------------------------- notifications
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 100);
            $table->string('title', 200);
            $table->text('message');
            $table->json('data')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users');
            $table->index('user_id');
            $table->index('read_at');
        });

        // ------------------------------------------------------------ audit_logs
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 100);
            $table->string('entity_type', 100)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['entity_type', 'entity_id']);
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('patrol_checkins');
        Schema::dropIfExists('patrol_sessions');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('patrol_schedule_assignments');
        Schema::dropIfExists('patrol_schedules');
        Schema::dropIfExists('route_checkpoints');
        Schema::dropIfExists('patrol_routes');
        Schema::dropIfExists('checkpoints');
        Schema::dropIfExists('areas');
    }
};
