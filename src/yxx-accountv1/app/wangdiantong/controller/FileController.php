<?php
declare (strict_types=1);

namespace app\wangdiantong\controller;

use app\common\Controller;
use app\common\YxxCsv;
use app\common\YxxExcel;
use think\facade\Db;
use think\Request;

class FileController extends Controller
{
    public function list()
    {
        $params = [];

        $query = Db::name('wangdiantong_file');
        $list  = $query->order('id', 'asc')->paginate([
            'query' => $params,
            'list_rows' => 15,
        ]);
        $page  = $list->render();
        $list  = $list->toArray();

        $params['list'] = $list;
        $params['page'] = $page;
        return view('list', $params);
    }

    public function create()
    {
        $params = [];

        return view('create', $params);
    }

    /**
     * 保存新建的资源
     *
     * @param \think\Request $request
     * @return \think\Response
     */
    public function save(Request $request)
    {
        $params = $request->all();

        $path = 'wangdiantong_file';

        $saveName = \think\facade\Filesystem::disk('public')->putFile($path, $params['file'], 'uniqid');

        $insertData                   = [];
        $insertData['name']           = $params['name'];
        $insertData['order_filename'] = $params['file']->getOriginalName();
        $insertData['order_path']     = $saveName;
        $insertData['status']         = 0;
        $insertData['create_time']    = time();

        $ret = Db::name('wangdiantong_file')->insert($insertData);
        if ($ret) {
            return redirect((string)url('wangdiantong/file/list'));
        } else {
            return redirect((string)url('wangdiantong/file/list'));
        }
    }

    public function edit()
    {
        $fileId   = \request()->param('id');
        $fileInfo = Db::name('wangdiantong_file')->where(['id' => $fileId])->find();

        if (empty($fileInfo)) {
            dd('数据不存在');
        }
        $configData  = !empty($fileInfo['dataJSON']) ? json_decode($fileInfo['dataJSON'], true) : [];
        $excelRowNum = (isset($configData['excelRowNum']) && !empty($configData['excelRowNum'])) ? $configData['excelRowNum'] : 0;
        $excelTitle  = (isset($configData['excelTitle']) && !empty($configData['excelTitle'])) ? $configData['excelTitle'] : ['A', 'B', 'G', 'L', 'O'];

        $cacheKey       = 'wangdiantong_file_excel-title-' . date('Ymd') . '-' . $fileId;
        $excelTitleData = cache($cacheKey);
        if (empty($excelTitleData)) {
            $excelModel     = new YxxExcel();
            $excelTitleData = $excelModel->readTitle($fileInfo['order_path']);
            cache($cacheKey, $excelTitleData);
        }

        $params = [
            'id' => $fileId,
            'excelRowNum' => ['content' => $excelRowNum],
            'excelTitle' => ['data' => $excelTitleData, 'content' => $excelTitle],
        ];

        return view('edit', $params);
    }

    public function saveEdit(Request $request)
    {
        $params = $request->all();

        $configData                = [];
        $configData['excelRowNum'] = $params['excelRowNum'];
        $configData['excelTitle']  = $params['excelTitle'];

        $ret = Db::name('wangdiantong_file')->where(['id' => $params['id']])->update(['dataJSON' => json_encode($configData)]);
        if ($ret) {
            return redirect((string)url('wangdiantong/file/list'));
        } else {
            return redirect((string)url('wangdiantong/file/list'));
        }
    }

    public function deleteExpress()
    {
        $fileId   = \request()->param('id');
        $fileInfo = Db::name('wangdiantong_file')->where(['id' => $fileId, 'status' => 1])->find();

        if (empty($fileInfo)) {
            echo json_encode(['success' => 0, 'message' => '数据不存在']);
            die();
        }

        $dbTableName = 'wangdiantong_order';
        // 启动事务
        Db::startTrans();
        try {
            $retOrder = Db::name($dbTableName)->where(['wangdiantong_file_id' => $fileId])->delete();
            Db::name('wangdiantong_file')->where(['id' => $fileId])->update(['status' => 0]);
            // 提交事务
            Db::commit();
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            echo json_encode(['success' => 0, 'message' => $e->getMessage()]);
            die();
        }

        echo json_encode(['success' => 1, 'message' => '删除快递成功']);
        die();
    }

    public function import()
    {
        set_time_limit(0);
        $fileId   = \request()->param('id');
        $fileInfo = Db::name('wangdiantong_file')->where(['id' => $fileId, 'status' => 0])->find();

        if (empty($fileInfo)) {
            echo json_encode(['success' => 0, 'message' => '数据不存在']);
            die();
        }
        $configData  = !empty($fileInfo['dataJSON']) ? json_decode($fileInfo['dataJSON'], true) : [];
        $excelRowNum = (isset($configData['excelRowNum']) && !empty($configData['excelRowNum'])) ? $configData['excelRowNum'] : 0;
        $excelTitle  = (isset($configData['excelTitle']) && !empty($configData['excelTitle'])) ? $configData['excelTitle'] : ['A', 'B', 'G', 'L', 'O'];

        $insertData = [];
        // 获取 excel 数据
        if (true) {

            $excelModel = new YxxExcel();
            $excelModel->setExcelRowNum((int)$excelRowNum);
            $excelModel->setColArr($excelTitle);
            $data = $excelModel->read($fileInfo['order_path']);

            foreach ($data as $v) {
                $str          = json_encode($v);
                $insertData[] = [
                    "wangdiantong_file_id" => $fileId,
                    "order_number" => $v[0],
                    "order_express_number" => $v[2],
                    "express_company" => $v[1],
                    "express_address" => $v[3],
                    "weight" => $v[4],
                    "dataJSON" => $str,
                ];
            }

        } else {

            //0 => "原始单号" 5 => "物流公司" 6 => "物流单号" 11 => "收货地址" 13 => "实际重量"
//原始单号	店铺	支付时间	状态	发货状态	物流公司	物流单号	发货时间	货品数量	货品种类	收货人	收货地区	收货地址	邮资成本	实际重量	货品商家编码

            $colArr   = ['0', '2', '3', '8', '11'];
            $colArr   = ['0', '5', '6', '11', '14'];
            $csvModel = new YxxCsv();
            $csvModel->setColArr($colArr);
            $data = $csvModel->read($fileInfo['order_path']);

            foreach ($data as $v) {
                $str          = json_encode($v);
                $insertData[] = [
                    "wangdiantong_file_id" => $fileId,
                    "order_number" => $v[0],
                    "order_express_number" => $v[2],
                    "express_company" => $v[1],
                    "express_address" => $v[3],
                    "weight" => $v[4],
                    "dataJSON" => $str,
                ];
            }
        }

        // 启动事务
        Db::startTrans();
        try {
            if (!empty($insertData)) {
                Db::name('wangdiantong_order')->insertAll($insertData, 1000);
            }
            $updateArr           = [];
            $updateArr['status'] = 1;
            $updateArr['num']    = count($insertData);

            Db::name('wangdiantong_file')->where(['id' => $fileId])->update($updateArr);
            // 提交事务
            Db::commit();
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            echo json_encode(['success' => 0, 'message' => $e->getMessage()]);
            die();
        }

        echo json_encode(['success' => 1, 'message' => '导入成功']);
        die();
    }
}
