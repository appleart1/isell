<?php
require_once 'Catalog.php';
class PluginManager extends Catalog{
    public $min_level=4;
    private $plugin_folder='application/plugins/';
    
    public $listFetch=[];
    public function listFetch(){
	$plugins_folders=$this->scanFolder($this->plugin_folder);
	$plugins=[];
	foreach($plugins_folders as $plugin_folder){
	    if( strpos($plugin_folder, 'Reports')!==false ){
		continue;
	    }
	    $headers=$this->get_plugin_headers($plugin_folder);
	    if( isset($headers['user_level']) && $headers['user_level']<=$this->Hub->svar('user_level') ){
		$plugins[]=$headers;
	    }
	}
	function sort_bygroup($a,$b){
	    if( !isset($a['group_name']) || !isset($b['group_name']) || $a['group_name']==$b['group_name'] ){
		return 0;
	    }
	    return ($a['group_name']>$b['group_name'])?1:-1;
	}
	usort($plugins,'sort_bygroup');
	return $plugins;
    }
    private function scanFolder( $path ){
	$this->Hub->set_level(1);
	$files = array_diff(scandir($path), array('.', '..'));
	arsort($files);
	return array_values($files);	
    }
    private function get_plugin_headers( $plugin_system_name ){
	$path=$this->plugin_folder.$plugin_system_name."/models/".$plugin_system_name.".php";
	if( !file_exists($path) ){
	    $path=$this->plugin_folder.$plugin_system_name."/".$plugin_system_name.".php";// Support for older plugins
	    if( !file_exists($path) ){
		return [];
	    }
	}
	$plugin_data = file_get_contents($path,true);
	
	preg_match ('|Group Name:(.*)$|mi', $plugin_data, $group_name);
	preg_match ('|User Level:(.*)$|mi', $plugin_data, $user_level);
	preg_match ('|Plugin Name:(.*)$|mi', $plugin_data, $name);
	preg_match ('|Plugin URI:(.*)$|mi', $plugin_data, $uri);
	preg_match ('|Version:(.*)|i', $plugin_data, $version);
	preg_match ('|Description:(.*)$|mi', $plugin_data, $description);
	preg_match ('|Author:(.*)$|mi', $plugin_data, $author_name);
	preg_match ('|Author URI:(.*)$|mi', $plugin_data, $author_uri);
	return [
	    'system_name'=>$plugin_system_name,
	    'group_name'=>isset($group_name[1])?trim($group_name[1]):null,
	    'user_level'=>isset($user_level[1])?trim($user_level[1]):2,
	    'plugin_name'=>isset($name[1])?trim($name[1]):null,
	    'plugin_uri'=>isset($uri[1])?trim($uri[1]):null,
	    'plugin_version'=>isset($version[1])?trim($version[1]):null,
	    'plugin_description'=>isset($description[1])?trim($description[1]):null,
	    'plugin_author'=>isset($author_name[1])?trim($author_name[1]):null,
	    'plugin_author_uri'=>isset($author_uri[1])?trim($author_uri[1]):null
	];
    }
    public $settingsDataFetch=[];
    public function settingsDataFetch($plugin_system_name){
	$settings_data=$this->get_row("SELECT * FROM plugin_list WHERE plugin_system_name='$plugin_system_name'");
	if( $settings_data && $settings_data->plugin_settings ){
	    $settings_data->plugin_settings=  json_decode($settings_data->plugin_settings);
	} else {
	    $settings_data=new stdClass;
	}
	return $settings_data;
    }
    public $settingsAllFetch=['system_name'=>'string'];
    public function settingsAllFetch($plugin_system_name){
	$settings_file=$this->plugin_folder.$plugin_system_name."/settings.html";
	$settings_data=$this->settingsDataFetch($plugin_system_name);
	$settings_data->html=file_exists($settings_file)?file_get_contents($settings_file):'';
	return $settings_data;
    }
    
    public $settingsSave=['plugin_system_name'=>'string','settings_json'=>'string'];
    public function settingsSave($plugin_system_name,$settings_json){
	$sql="INSERT INTO 
		plugin_list 
	    SET 
		plugin_system_name='$plugin_system_name',
		plugin_settings='$settings_json'
	    ON DUPLICATE KEY UPDATE
		plugin_settings='$settings_json'";
	$this->query($sql);
	return $this->db->affected_rows();
    }
    
    public $activate=['plugin_system_name'=>'string'];
    public function activate($plugin_system_name){
	$data=[
	    'plugin_system_name'=>$plugin_system_name,
	    'is_activated'=>1
	];
	$ok=$this->pluginUpdate($plugin_system_name,$data);
	$this->Hub->pluginInitTriggers();
	$this->plugin_do($plugin_system_name, 'activate');
	return $ok;
    }
    
    public $deactivate=['plugin_system_name'=>'string'];
    public function deactivate($plugin_system_name){
	$data=[
	    'plugin_system_name'=>$plugin_system_name,
	    'is_activated'=>0
	];
	$ok=$this->pluginUpdate($plugin_system_name,$data);
	$this->Hub->pluginInitTriggers();
	$this->plugin_do($plugin_system_name, 'deactivate');
	return $ok;
    }
    
    public $install=['plugin_system_name'=>'string'];
    public function install($plugin_system_name){
	$headers=$this->get_plugin_headers( $plugin_system_name );
	$data=[
	    'plugin_system_name'=>$plugin_system_name,
	    'trigger_before'=>$headers['trigger_before'],
	    'is_installed'=>1,
	    'is_activated'=>1
	];
	$ok=$this->insert('plugin_list',$data);
	$this->Hub->pluginInitTriggers();
	$this->plugin_do($plugin_system_name, 'install');
	return $ok;
    }
    
    public $uninstall=['plugin_system_name'=>'string'];
    public function uninstall($plugin_system_name){
	$ok=$this->delete('plugin_list',['plugin_system_name'=>$plugin_system_name]);
	$this->plugin_do($plugin_system_name, 'uninstall');
	$this->Hub->pluginInitTriggers();
	return $ok;
    }
    
    private function pluginUpdate($plugin_system_name,$data){
	return $this->update('plugin_list',$data,['plugin_system_name'=>$plugin_system_name]);
    }
    
    public function plugin_do($plugin_system_name, $plugin_method, $plugin_method_args=[]){
	$path=$this->plugin_folder.$plugin_system_name."/models/".$plugin_system_name.".php";
	if( !file_exists($path) ){
	    $path=$this->plugin_folder.$plugin_system_name."/".$plugin_system_name.".php";// Support for older plugins
	    if( !file_exists($path) ){
		return [];
	    }
	}
	require_once $path;
	
	//new MiSell($this->Hub);
	
	    $Plugin=$this->Hub->load_model($plugin_system_name);

	//$Plugin=new $plugin_system_name();
	//$Plugin->Hub=$this->Hub;
	if( method_exists($Plugin, $plugin_method) ){
	    return call_user_func_array([$Plugin,$plugin_method], $plugin_method_args);
	}
	return null;
    }
}
