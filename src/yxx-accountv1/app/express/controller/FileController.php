<?php

declare(strict_types=1);

namespace app\express\controller;

use app\common\YxxExcel;
use app\common\YxxCsv;
use think\facade\Db;
use think\Request;
use app\common\Controller;

class FileController extends Controller
{
    public function list()
    {
        $params = [];

        $query = Db::name('express_file');
        $list  = $query->order('id', 'asc')->paginate([
            'query' => $params,
            'list_rows' => 15,
        ]);
        $page  = $list->render();
        $list  = $list->toArray();

        $params['list']       = $list;
        $params['page']       = $page;
        $params['statistics'] = $this->statistics();
        return view('list', $params);
    }

    /**
     * 统计
     */
    public function statistics()
    {
        $ret = [
            'express_total' => 0, //运单总数
            'express_total_no' => 0,//未取得商品信息的运单总数
        ];
        $all = Db::name('express_file')->select();
        foreach ($all as $row) {
            $ret['express_total']    += $row['num_order'];
            $ret['express_total_no'] += $row['num_no'];
        }
        return $ret;
    }

    public function create()
    {
        $params = [];

        return view('create', $params);
    }

    public function deleteExpress()
    {
        $fileId   = \request()->param('id');
        $fileInfo = Db::name('express_file')->where(['id' => $fileId, 'status' => 1])->find();

        if (empty($fileInfo)) {
            echo json_encode(['success' => 0, 'message' => '数据不存在']);
            die();
        }

        $expressTypeEname = yxx_config_ename('EXPRESS_TYPE', $fileInfo['type']);
        $dbTableName      = 'express_' . $expressTypeEname;
        // 启动事务
        Db::startTrans();
        try {
            $retOrder = Db::name($dbTableName)->where(['express_file_id' => $fileId])->delete();
            Db::name('express_file')->where(['id' => $fileId])->update(['status' => 0]);
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

    public function edit()
    {
        $fileId   = \request()->param('id');
        $fileInfo = Db::name('express_file')->where(['id' => $fileId])->find();

        if (empty($fileInfo)) {
            dd('数据不存在');
        }
        $configData  = !empty($fileInfo['dataJSON']) ? json_decode($fileInfo['dataJSON'], true) : [];
        $excelRowNum = (isset($configData['excelRowNum']) && !empty($configData['excelRowNum'])) ? $configData['excelRowNum'] : 0;
        $excelTitle  = (isset($configData['excelTitle']) && !empty($configData['excelTitle'])) ? $configData['excelTitle'] : $this->getColArr($fileInfo['type']);

        $cacheKey       = 'express_file_excel-title' . date('Ymd') . '-' . $fileId;
        $excelTitleData = cache($cacheKey);
        if (empty($excelTitleData)) {
            $excelModel = new YxxExcel();
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

        $ret = Db::name('express_file')->where(['id' => $params['id']])->update(['dataJSON' => json_encode($configData)]);
        if ($ret) {
            return redirect((string)url('express/file/list'));
        } else {
            return redirect((string)url('express/file/list'));
        }
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

        $path = 'express_file';

        $saveOrderName = \think\facade\Filesystem::disk('public')->putFile($path, $params['order_file'], 'uniqid');

        $insertData                   = [];
        $insertData['name']           = $params['name'];
        $insertData['order_filename'] = $params['order_file']->getOriginalName();
        $insertData['order_path']     = $saveOrderName;
        $insertData['type']           = $params['type'];
        $insertData['status']         = 0;
        $insertData['create_time']    = time();

        $ret = Db::name('express_file')->insert($insertData);
        if ($ret) {
            return redirect((string)url('express/file/list'));
        } else {
            return redirect((string)url('express/file/list'));
        }
    }

    public function import()
    {
        $fileId   = \request()->param('id');
        $fileInfo = Db::name('express_file')->where(['id' => $fileId, 'status' => 0])->find();

        if (empty($fileInfo)) {
            echo json_encode(['success' => 0, 'message' => '数据不存在']);
            die();
        }
        $configData  = !empty($fileInfo['dataJSON']) ? json_decode($fileInfo['dataJSON'], true) : [];
        $excelRowNum = (isset($configData['excelRowNum']) && !empty($configData['excelRowNum'])) ? $configData['excelRowNum'] : 0;
        $excelTitle  = (isset($configData['excelTitle']) && !empty($configData['excelTitle'])) ? $configData['excelTitle'] : $this->getColArr($fileInfo['type']);

        // 获取 excel 数据
        $excelModel = new YxxExcel();
        $excelModel->setExcelRowNum((int)$excelRowNum);
        $excelModel->setColArr($excelTitle);
        $orderData = $excelModel->read($fileInfo['order_path'], true);

        if ($fileInfo['type'] == yxx_config_value('EXPRESS_TYPE', 'T4')) {
            array_shift($orderData);
        }

        // 组装
        $insertOrderData = [];
        foreach ($orderData as $v) {
            $orderExpressNumber = isset($v[0]) ? $v[0] : '';
            $expressWeight      = isset($v[1]) ? $v[1] : 0;
            if ($fileInfo['type'] == yxx_config_value('EXPRESS_TYPE', 'T2')) {
                if (is_numeric($expressWeight)) {
                    $expressWeight = $expressWeight / 1000;
                }
            }
            $str               = json_encode($v);
            $insertOrderData[] = [
                "express_file_id" => $fileId,
//                "order_number" => trim($orderExpressNumber),
                "order_number" => $orderExpressNumber,
                "express_weight" => $expressWeight,
                "dataJSON" => $str,
            ];
        }

        $expressTypeEname = yxx_config_ename('EXPRESS_TYPE', $fileInfo['type']);
        $dbTableName      = 'express_' . $expressTypeEname;
        // 启动事务
        Db::startTrans();
        try {
            $retOrder = Db::name($dbTableName)->insertAll($insertOrderData, 1000);
            if (!empty($retOrder)) {
                $updateArr              = [];
                $updateArr['status']    = 1;
                $updateArr['num_order'] = count($insertOrderData);

                Db::name('express_file')->where(['id' => $fileId])->update($updateArr);
            }
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

    public function export()
    {
        $fileId   = \request()->param('id');
        $fileInfo = Db::name('express_file')->where(['id' => $fileId])->find();

        $expressTypeEname = yxx_config_ename('EXPRESS_TYPE', $fileInfo['type']);
        $dbTableName      = 'express_' . $expressTypeEname;

        $expressTypeName = yxx_config_name('EXPRESS_TYPE', $fileInfo['type']);
        $fileName        = $expressTypeName . date('Ymd');
        if ($fileInfo['name'] != '') {
            $fileName = $fileInfo['name'] . $fileName;
        }

        $data = [];
        $list = Db::name($dbTableName)->where(['express_file_id' => $fileId])->select()->toArray();
        foreach ($list as $key => $value) {
            $temp   = [];
            $isDiff = '';
            if (!empty($value['weight']) && !empty($value['express_weight'])) {
                if ($value['weight'] == $value['express_weight']) {
                    $isDiff = '已核对';
                } else {
                    $leveExpress = $this->judgeLeveExpress($value['express_weight']);
                    $leveWdt     = $this->judgeLeveWdt($value['weight']);
                    if ($leveExpress == '等级不存在' || $leveWdt == '等级不存在') {
                        $isDiff = '等级不存在';
                    } else {
                        if ($leveWdt < $leveExpress) {
                            $isDiff = '有差异(旺店通：' . $leveWdt . '，快递：' . $leveExpress . ')';
                        } else {
                            $isDiff = '已核对';
                        }
                    }
                }
            }
            $temp['orderNum']       = $value['order_number'];
            $temp['member']         = $value['member'];
            $temp['shopinfo']       = $value['shopinfo'];
            $temp['express_weight'] = $value['express_weight'];
            $temp['weight']         = $value['weight'];
            $temp['isDiff']         = $isDiff;

            $data[] = $temp;
        }
        // 导出excel
        $headerArr  = [
            'orderNum' => '运单号',
            'member' => '买家会员名',
            'shopinfo' => '所属店铺',
            'express_weight' => '快递重量',
            'weight' => '旺店通重量',
            'isDiff' => '差异标注',
        ];
        $excelModel = new YxxExcel();
        $excelModel->setHeaderArr($headerArr);
        $excelModel->export($data, $fileName);
        exit;
    }

    /**
     * 导出产品排行榜
     */
    public function exportRank()
    {
        $fileId   = \request()->param('id');
        $fileInfo = Db::name('express_file')->where(['id' => $fileId])->find();

        $expressTypeEname = yxx_config_ename('EXPRESS_TYPE', $fileInfo['type']);
        $dbTableName      = 'express_' . $expressTypeEname;

        $expressTypeName = yxx_config_name('EXPRESS_TYPE', $fileInfo['type']);
        $fileName        = $expressTypeName . date('Ymd');
        if ($fileInfo['name'] != '') {
            $fileName = $fileInfo['name'] . $fileName;
        }
        $fileName = '排行榜' . $fileName;

        $data = Db::name($dbTableName)->field('order_number,member,weight,shopinfo,count(id) as num')
            ->where(['express_file_id' => $fileId])
            ->group('shopinfo')
            ->having('num>1')
            ->order('num', 'desc')
            ->select()->toArray();

        // 导出excel
        $headerArr  = [
            'shopinfo' => '导出商品详情',
            'num' => '数目排行榜',
            'weight' => '重量',
        ];
        $excelModel = new YxxExcel();
        $excelModel->setHeaderArr($headerArr);
        $excelModel->export($data, $fileName);
    }

    public function exportCsv()
    {
        $fileId   = \request()->param('id');
        $fileInfo = Db::name('express_file')->where(['id' => $fileId])->find();

        $expressTypeEname = yxx_config_ename('EXPRESS_TYPE', $fileInfo['type']);
        $dbTableName      = 'express_' . $expressTypeEname;

        $expressTypeName = yxx_config_name('EXPRESS_TYPE', $fileInfo['type']);
        $fileName        = $expressTypeName . date('Ymd');
        if ($fileInfo['name'] != '') {
            $fileName = $fileInfo['name'] . $fileName;
        }
        $fileName = $fileName . '.csv';

        $data = [];
        $list = Db::name($dbTableName)->where(['express_file_id' => $fileId])->select()->toArray();
        foreach ($list as $key => $value) {

            if ($value['order_number'] != '') {
                $temp     = [];
                $orderNum = '="' . $value['order_number'] . '"';
                $temp[]   = $orderNum;
                $temp[]   = $value['member'];
                $temp[]   = $value['shopinfo'];

                $data[] = $temp;
            }
        }

        $headerArr = [
            'orderNum' => '运单号',
            'member' => '买家会员名',
            'shopinfo' => '导出商品详情',
        ];
        $csvModel  = new YxxCsv();
        $csvModel->setHeaderArr($headerArr);
        $csvModel->export($data, $fileName);
    }

    /**
     * 快递重量 运费重量区间所对应的等级
     * @param $weight
     * @return float|int
     */
    public function judgeLeveExpress($weight)
    {
        $weight = floatval($weight);
        if ($weight > 0 && $weight <= 0.5) {
            $leve = 0.5;
        } elseif ($weight > 0.5 && $weight <= 1) {
            $leve = 1;
        } elseif ($weight > 1 && $weight <= 2) {
            $leve = 2;
        } elseif ($weight > 2 && $weight <= 3) {
            $leve = 3;
        } elseif ($weight > 3 && $weight <= 4) {
            $leve = 4;
        } elseif ($weight > 4 && $weight <= 5) {
            $leve = 5;
        } elseif ($weight > 5 && $weight <= 6) {
            $leve = 6;
        } elseif ($weight > 6 && $weight <= 7) {
            $leve = 7;
        } elseif ($weight > 7 && $weight <= 8) {
            $leve = 8;
        } elseif ($weight > 8 && $weight <= 9) {
            $leve = 9;
        } elseif ($weight > 9 && $weight <= 10) {
            $leve = 10;
        } else {
            $leve = '等级不存在';
        }

        return $leve;
    }

    /**
     * 旺店通 运费重量区间所对应的等级
     * @param $weight
     * @return float|int
     */
    public function judgeLeveWdt($weight)
    {
        $weight = floatval($weight);
        if ($weight > 0 && $weight <= 0.44) {
            $leve = 0.5;
        } elseif ($weight > 0.44 && $weight <= 0.85) {
            $leve = 1;
        } elseif ($weight > 0.85 && $weight <= 1.85) {
            $leve = 2;
        } elseif ($weight > 1.85 && $weight <= 2.85) {
            $leve = 3;
        } elseif ($weight > 2.85 && $weight <= 3.85) {
            $leve = 4;
        } elseif ($weight > 3.85 && $weight <= 4.85) {
            $leve = 5;
        } elseif ($weight > 4.85 && $weight <= 5.85) {
            $leve = 6;
        } elseif ($weight > 5.85 && $weight <= 6.85) {
            $leve = 7;
        } elseif ($weight > 6.85 && $weight <= 7.85) {
            $leve = 8;
        } elseif ($weight > 7.85 && $weight <= 8.85) {
            $leve = 9;
        } elseif ($weight > 8.85 && $weight <= 9.85) {
            $leve = 10;
        } else {
            $leve = '等级不存在';
        }

        return $leve;
    }

    /**
     * @return array
     */
    public function getColArr($type = 0)
    {
        $colArr = ['A', 'B', 'C', 'D', 'E', 'G', 'R'];
        switch ($type) {
            case yxx_config_value('EXPRESS_TYPE', 'T1'):
                $colArr = ['A', 'C'];
                break;
            case yxx_config_value('EXPRESS_TYPE', 'T2'):
                $colArr = ['C', 'H'];
                break;
            case yxx_config_value('EXPRESS_TYPE', 'T3'):
                $colArr = ['A', 'K'];
                break;
            case yxx_config_value('EXPRESS_TYPE', 'T4'):
                $colArr = ['B'];
                break;
            case yxx_config_value('EXPRESS_TYPE', 'T5'):
                $colArr = ['B', 'D'];
                break;
            case yxx_config_value('EXPRESS_TYPE', 'T6'):
                $colArr = ['C', 'E'];
                break;
            default:
                break;
        }
        return $colArr;
    }
}
