<?php

namespace app\portal\controller;

use cmf\controller\AdminBaseController;
use think\facade\Db;

class AdminExternalContentController extends AdminBaseController
{
    /**
     * External content synchronization status
     * @adminMenu(
     *     'name'   => '外部内容同步',
     *     'parent' => 'portal/AdminIndex/default',
     *     'display'=> true,
     *     'hasView'=> true,
     *     'order'  => 10020,
     *     'icon'   => '',
     *     'remark' => 'SEC 与 GlobeNewswire 同步状态',
     *     'param'  => ''
     * )
     * @return mixed
     */
    public function index()
    {
        $source = trim((string)$this->request->param('source', ''));
        $status = trim((string)$this->request->param('status', ''));

        $query = Db::name('portal_external_content')->alias('external')
            ->leftJoin('portal_post post', 'post.id = external.portal_post_id')
            ->field('external.*,post.post_status,post.delete_time')
            ->order('external.synced_at DESC,external.id DESC');

        if (in_array($source, ['sec', 'globenewswire'], true)) {
            $query->where('external.source', $source);
        }
        if (in_array($status, ['synced', 'error', 'manual', 'protected'], true)) {
            $query->where('external.sync_status', $status);
        }

        $list = $query->paginate(30, false, ['query' => ['source' => $source, 'status' => $status]]);
        $counts = Db::name('portal_external_content')
            ->field('sync_status,COUNT(*) AS total')
            ->group('sync_status')
            ->column('total', 'sync_status');

        $this->assign('list', $list->items());
        $this->assign('page', $list->render());
        $this->assign('counts', $counts);
        $this->assign('source', $source);
        $this->assign('status', $status);
        return $this->fetch();
    }
}
