<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->addColumn('contacts', 'client_request_id', fn (Blueprint $table) =>
            $table->uuid('client_request_id')->nullable()->after('id')
        );
        $this->addColumn('contacts', 'request_fingerprint', fn (Blueprint $table) =>
            $table->char('request_fingerprint', 64)->nullable()->after('client_request_id')
        );
        $this->addIndex('contacts', ['client_request_id'], 'contacts_client_request_id_unique', true);

        $this->addColumn('portfolio_media', 'client_request_id', fn (Blueprint $table) =>
            $table->uuid('client_request_id')->nullable()->after('portfolio_item_id')
        );
        $this->addColumn('portfolio_media', 'content_sha256', fn (Blueprint $table) =>
            $table->char('content_sha256', 64)->nullable()->after('file_path')
        );
        $this->addColumn('portfolio_media', 'mime_type', fn (Blueprint $table) =>
            $table->string('mime_type', 120)->nullable()->after('content_sha256')
        );
        $this->addColumn('portfolio_media', 'size_bytes', fn (Blueprint $table) =>
            $table->unsignedBigInteger('size_bytes')->nullable()->after('mime_type')
        );
        $this->addColumn('portfolio_media', 'original_name', fn (Blueprint $table) =>
            $table->string('original_name')->nullable()->after('size_bytes')
        );
        $this->addIndex(
            'portfolio_media',
            ['portfolio_item_id', 'client_request_id'],
            'portfolio_media_item_request_unique',
            true
        );
        $this->addIndex(
            'portfolio_media',
            ['portfolio_item_id', 'content_sha256'],
            'portfolio_media_item_content_lookup'
        );

        $this->addColumn('users', 'profile_revision', fn (Blueprint $table) =>
            $table->unsignedBigInteger('profile_revision')->default(0)->after('profile_image')
        );

        if (!Schema::hasTable('profile_update_receipts')) {
            Schema::create('profile_update_receipts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->uuid('client_request_id');
                $table->char('request_fingerprint', 64);
                $table->unsignedBigInteger('profile_revision');
                $table->timestamps();
                $table->unique(['user_id', 'client_request_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_update_receipts');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('profile_revision');
        });

        Schema::table('portfolio_media', function (Blueprint $table): void {
            $table->dropUnique('portfolio_media_item_request_unique');
            $table->dropIndex('portfolio_media_item_content_lookup');
            $table->dropColumn([
                'client_request_id',
                'content_sha256',
                'mime_type',
                'size_bytes',
                'original_name',
            ]);
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropUnique(['client_request_id']);
            $table->dropColumn(['client_request_id', 'request_fingerprint']);
        });
    }

    private function addColumn(string $tableName, string $column, Closure $definition): void
    {
        if (!Schema::hasColumn($tableName, $column)) {
            Schema::table($tableName, $definition);
        }
    }

    /** @param list<string> $columns */
    private function addIndex(
        string $tableName,
        array $columns,
        string $name,
        bool $unique = false
    ): void {
        if (Schema::hasIndex($tableName, $name)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $name, $unique): void {
            $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
        });
    }
};
