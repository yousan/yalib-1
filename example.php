<?php
include('yalib.php');

$sql = 'select * from wp_posts where post_status = :post_status;';
//$h = yalib::gi()->p($sql);
while($ret = yalib::gi()->p($sql)->f('post_status', 'publish')){
  //while($ret = $h->bv('post_status', 'publish')->f()){
  var_dump($ret['ID']);
}
var_dump(yalib::gi()->getBoundSql());
?>
