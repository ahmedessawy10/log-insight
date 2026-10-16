<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('li_log_files', function (Blueprint $t) {
            $t->id();
            $t->string('path', 500)->unique();
            $t->string('type', 30)->default('laravel');
            $t->string('host', 120)->nullable();
            $t->unsignedBigInteger('inode')->nullable();
            $t->unsignedBigInteger('last_offset')->default(0);
            $t->unsignedBigInteger('size')->default(0);
            $t->timestamp('last_ingested_at')->nullable();
            $t->timestamps();
        });

        Schema::create('li_issues', function (Blueprint $t) {
            $t->id();
            $t->string('fingerprint', 40)->unique();
            $t->string('title', 500);
            $t->string('exception_class')->nullable();
            $t->string('level', 20);
            $t->timestamp('first_seen_at')->nullable()->index();
            $t->timestamp('last_seen_at')->nullable()->index();
            $t->unsignedBigInteger('occurrences')->default(0);
            $t->string('status', 20)->default('open')->index(); // open|resolved|ignored
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('regressed_at')->nullable();
            $t->string('resolved_by')->nullable();
            $t->timestamps();
        });

        Schema::create('li_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('log_file_id')->constrained('li_log_files')->cascadeOnDelete();
            $t->foreignId('issue_id')->nullable()->constrained('li_issues')->nullOnDelete();
            $t->timestamp('logged_at')->index();
            $t->string('level', 20);
            $t->string('channel', 60)->nullable();
            $t->string('environment', 60)->nullable();
            $t->text('message');
            $t->longText('context')->nullable();
            $t->longText('stack_trace')->nullable();
            $t->string('exception_class')->nullable()->index();
            $t->string('exception_file', 500)->nullable();
            $t->unsignedInteger('exception_line')->nullable();
            $t->string('fingerprint', 40)->nullable()->index();
            $t->string('request_id', 80)->nullable()->index();
            $t->string('user_id', 80)->nullable()->index();
            $t->string('http_method', 10)->nullable();
            $t->unsignedSmallInteger('http_status')->nullable()->index();
            $t->string('url', 500)->nullable();
            $t->string('ip', 64)->nullable();
            $t->unsignedBigInteger('byte_offset');
            $t->timestamp('created_at')->nullable();

            $t->unique(['log_file_id', 'byte_offset']);
            $t->index(['level', 'logged_at']);
            $t->index(['fingerprint', 'logged_at']);
        });

        Schema::create('li_tags', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->unique();
            $t->string('color', 9)->default('#6b7280');
            $t->string('type', 10)->default('auto'); // auto|rule|manual
            $t->timestamps();
        });

        Schema::create('li_entry_tag', function (Blueprint $t) {
            $t->foreignId('entry_id')->constrained('li_entries')->cascadeOnDelete();
            $t->foreignId('tag_id')->constrained('li_tags')->cascadeOnDelete();
            $t->primary(['entry_id', 'tag_id']);
            $t->index('tag_id');
        });

        Schema::create('li_tag_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tag_id')->constrained('li_tags')->cascadeOnDelete();
            $t->string('field', 30);    // message|channel|exception_class|stack_trace|context
            $t->string('operator', 20); // contains|equals|starts_with|regex
            $t->string('value', 200);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('li_saved_filters', function (Blueprint $t) {
            $t->id();
            $t->string('user_id', 80)->nullable()->index();
            $t->string('name', 100);
            $t->text('query');
            $t->boolean('is_shared')->default(false);
            $t->timestamps();
        });

        Schema::create('li_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('user_id', 80)->nullable()->index();
            $t->string('user_label')->nullable();
            $t->string('action', 40)->index();
            $t->string('target', 255)->nullable();
            $t->text('details')->nullable();
            $t->string('ip', 64)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        foreach (['li_audit_logs', 'li_saved_filters', 'li_tag_rules', 'li_entry_tag', 'li_tags', 'li_entries', 'li_issues', 'li_log_files'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
