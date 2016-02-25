<?php
/**
 * Created by PhpStorm.
 * Date: 2015/7/22
 * Time: 14:54
 */

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
define('CURR_PATH',str_replace("\\", "/", dirname(__FILE__))) ;
require_once CURR_PATH . '/vendor/autoload.php' ;


ini_set('xdebug.max_nesting_level', 3000);

$parser = new PhpParser\Parser(new PhpParser\Lexer\Emulative) ;
$nodeDumper = new PhpParser\NodeDumper();
$code = "<?php echo 'xx'. hi\\getTarget();";
$file_name = 'D:\vul_test\2015\r\www.modefied\www\app\controllers\HttpRpcController.php';
$code = file_get_contents($file_name);
$json_file_name = 'rpc.json';
try{
    $stmts = $parser->parse($code);
    file_put_contents($json_file_name, $nodeDumper->dump($stmts));
    //echo $nodeDumper->dump($stmts), "\n";
}catch(Error $e){
    echo 'Parse Error'.$e->getMessage();
}




class MyNodeVistor extends NodeVisitorAbstract{
    public $rets = array();

    public function leaveNode(Node $node){
        if($node instanceof Node\Stmt\PropertyProperty){
                echo $node->name."</br>";
                array_push($this->rets, $node->name);
        }
    }
}

$vistor = new MyNodeVistor();
$traverser = new \PhpParser\NodeTraverser;
$traverser->addVisitor($vistor);
$traverser->traverse($stmts);
foreach($vistor->rets as $ret) {
    echo $ret;
}
