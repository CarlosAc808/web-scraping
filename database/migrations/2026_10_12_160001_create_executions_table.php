<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('robot_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('products')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['robot_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executions');
    }
};
