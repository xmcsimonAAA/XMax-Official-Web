<?php

use Phinx\Db\Adapter\MysqlAdapter;
use think\migration\Migrator;

class CreatePortalExternalContent extends Migrator
{
    public function up()
    {
        if (!$this->hasTable('portal_external_content')) {
            $this->table('portal_external_content', ['comment' => 'External content synchronization records'])
                ->addColumn('source', 'string', [
                    'limit' => 32,
                    'null' => false,
                    'comment' => 'sec or globenewswire',
                ])
                ->addColumn('external_id', 'string', [
                    'limit' => 100,
                    'null' => false,
                    'comment' => 'Stable source identifier',
                ])
                ->addColumn('portal_post_id', 'biginteger', [
                    'signed' => false,
                    'default' => 0,
                    'null' => false,
                ])
                ->addColumn('source_url', 'string', [
                    'limit' => 1000,
                    'default' => '',
                    'null' => false,
                ])
                ->addColumn('source_title', 'string', [
                    'limit' => 255,
                    'default' => '',
                    'null' => false,
                ])
                ->addColumn('content_hash', 'string', [
                    'limit' => 64,
                    'default' => '',
                    'null' => false,
                ])
                ->addColumn('portal_hash', 'string', [
                    'limit' => 64,
                    'default' => '',
                    'null' => false,
                    'comment' => 'Detect manual article edits before refreshing',
                ])
                ->addColumn('sync_status', 'string', [
                    'limit' => 20,
                    'default' => 'pending',
                    'null' => false,
                ])
                ->addColumn('last_error', 'text', [
                    'null' => true,
                ])
                ->addColumn('source_published_time', 'integer', [
                    'signed' => false,
                    'default' => 0,
                    'null' => false,
                ])
                ->addColumn('synced_at', 'integer', [
                    'signed' => false,
                    'default' => 0,
                    'null' => false,
                ])
                ->addColumn('create_time', 'integer', [
                    'signed' => false,
                    'default' => 0,
                    'null' => false,
                ])
                ->addColumn('update_time', 'integer', [
                    'signed' => false,
                    'default' => 0,
                    'null' => false,
                ])
                ->addIndex(['source', 'external_id'], [
                    'unique' => true,
                    'name' => 'uniq_source_external_id',
                ])
                ->addIndex('portal_post_id')
                ->addIndex('sync_status')
                ->create();
        }

        if ($this->hasTable('portal_post')) {
            $this->table('portal_post')
                ->changeColumn('post_content', 'text', [
                    'limit' => MysqlAdapter::TEXT_MEDIUM,
                    'null' => true,
                    'comment' => 'Article content',
                ])
                ->update();
        }
    }

    public function down()
    {
        if ($this->hasTable('portal_external_content')) {
            $this->table('portal_external_content')->drop()->save();
        }
    }
}
