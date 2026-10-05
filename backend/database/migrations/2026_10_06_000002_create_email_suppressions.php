<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('reason', 30)->default('bounce');
            $table->text('details')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'email']);
        });
    }
    public function down(): void { Schema::dropIfExists('email_suppressions'); }
};
