<?php
require_once 'MoedeloSyncBase.php';
class MoedeloSyncProduct extends MoedeloSyncBase{
    private $sync_destination='moedelo_products';
    
    public function checkout(){
        $request=[
            'pageNo'=>1,
            'pageSize'=>100000,
            'afterDate'=>null,
            'beforeDate'=>null,
            'name'=>null
        ];
        if( $request['pageNo']==1 ){
            $this->query("UPDATE plugin_sync_entries SET remote_hash=NULL,remote_tstamp=NULL WHERE sync_destination='$this->sync_destination'");
        }
        $product_list=$this->apiExecute( 'good', 'GET', $request);
        foreach($product_list->response->ResourceList as $product){
            $this->query("
                SET
                    @local_id:=COALESCE((SELECT product_id FROM prod_list WHERE product_code='$product->Article'),0),
                    @remote_hash:=MD5(CONCAT(
                        '$product->Name',
                        '$product->Article',
                        '$product->UnitOfMeasurement',
                        ROUND('$product->SalePrice',5),
                        '$product->Producer'
                        )),
                    @remote_id:='$product->Id'
                ");
            $sql="INSERT INTO
                    plugin_sync_entries
                SET
                    sync_destination='$this->sync_destination',
                    local_id=@local_id,
                    remote_id=@remote_id,
                    remote_hash=@remote_hash
                ON DUPLICATE KEY UPDATE
                    remote_hash=@remote_hash
                ";
            $this->query($sql);
        }
        if( count($product_list)<$request['pageSize'] ){
            $this->query("DELETE FROM plugin_sync_entries WHERE sync_destination='$this->sync_destination' AND remote_hash IS NULL AND remote_tstamp IS NULL");
            return true;//down sync is finished
        }
        return false;
    }
    
    public function replicate(){
        $remote_insert_list = $this->getList('REMOTE_INSERT');
        $remote_update_list = $this->getList('REMOTE_UPDATE');
        $remote_delete_list = $this->getList('REMOTE_DELETE');
        
        $rows_done=0;
        $rows_done += $this->send($remote_insert_list, 'REMOTE_INSERT');
        $rows_done += $this->send($remote_update_list, 'REMOTE_UPDATE');
        $rows_done += $this->send($remote_delete_list, 'REMOTE_DELETE');
        return $rows_done;
    }
    
    private function getList($mode){
        $nomenclature_id = '11780959';
        $usd_rate=$this->Hub->pref('usd_ratio');
        $vat_rate = 20;
        $vat_position = 2;
        $product_type=0;
        
        $limit = 50;
        
        $select='';
        $table='';
        $where = '';
        $having='';

        switch( $mode ){
            case 'REMOTE_INSERT':
                $select=',pl.product_id';
                $table = 'LEFT JOIN
                    plugin_sync_entries pse ON pl.product_id=pse.local_id';
                $where= "WHERE local_id IS NULL";
                break;
            case 'REMOTE_UPDATE':
                $select=',pse.*';
                $table = 'JOIN
                    plugin_sync_entries pse ON pl.product_id=pse.local_id';
                $where= "WHERE sync_destination='$this->sync_destination'";
                $having="HAVING current_hash<>COALESCE(local_hash,'') OR current_hash<>COALESCE(remote_hash,'')";
                break;
            case 'REMOTE_DELETE':
                $select=',pse.*';
                $table = 'RIGHT JOIN
                    plugin_sync_entries pse ON pl.product_id=pse.local_id';
                $where= "WHERE sync_destination='$this->sync_destination' AND product_id IS NULL";
                break;
        }
        $sql="
            SELECT
                inner_table.*,
                MD5(CONCAT(Name,Article,UnitOfMeasurement,SalePrice,Producer)) current_hash
            FROM
            (SELECT
                $nomenclature_id NomenclatureId,
                ru Name,
                se.product_code Article,
                product_unit UnitOfMeasurement,
                $vat_rate Nds,
                ROUND(IF(pre.curr_code='USD',$usd_rate,1)*sell, 2) SalePrice,
                $product_type Type,
                $vat_position NdsPositionType,
                analyse_brand Producer
                $select
            FROM
                stock_entries se
                    JOIN
                prod_list pl ON se.product_code=pl.product_code
                    JOIN
                price_list pre ON se.product_code=pre.product_code AND label=''
                    $table
            $where) AS inner_table
            $having
            LIMIT $limit";
        return $this->get_list($sql);
    }
    
    private function send($product_list, $mode){
        if( empty($product_list) ){
            return 0;
        }
        $rows_done = 0;
        foreach($product_list as $product){
            $product_object = [
                "NomenclatureId" => $product->NomenclatureId,
                "Name" => $product->Name,
                "Article" => $product->Article,
                "UnitOfMeasurement" => $product->UnitOfMeasurement,
                "Nds" => $product->Nds,
                "SalePrice" => $product->SalePrice,
                "Type" => $product->Type,
                "NdsPositionType" => $product->NdsPositionType,
                "Producer" => $product->Producer
            ];
            if($mode === 'REMOTE_INSERT'){
                $response = $this->apiExecute('good', 'POST', $product_object)->response;
                if( isset($response->Id) ){
                    $this->logInsert($this->sync_destination,$product->product_id,$product->current_hash,$response->Id);
                    $rows_done++;
                } else {
                    $this->log("{$this->sync_destination} INSERT is unsuccessfull product_code:{$product->Article}");
                }
            } else 
            if($mode === 'REMOTE_UPDATE'){
                $httpcode = $this->apiExecute('good', 'PUT', $product_object, $product->remote_id)->httpcode;
                if( $httpcode==200 ){
                    $this->logUpdate($product->entry_id, $product->current_hash);
                    $rows_done++;
                } else {
                    $this->log("{$this->sync_destination} UPDATE is unsuccessfull product_code:{$product->Article}");
                }
            } else 
            if($mode === 'REMOTE_DELETE'){
                $httpcode = $this->apiExecute('good', 'REMOTE_DELETE', null, $product->remote_id)->httpcode;
                $this->logDelete($product->entry_id);
                $rows_done++;
                if( $httpcode!=204 ) {
                    $this->log("{$this->sync_destination} DELETE is unsuccessfull code:$httpcode product_code:{$product->Article}");
                }
            }
        }
        return $rows_done;
    }
    
    
}