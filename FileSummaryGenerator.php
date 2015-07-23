<?php

class FileSummaryGenerator {
    
    /**
     * 获取当前文件include的所有文件的summary
     * @param FileSummary $fileSummary
     * @return array(fileSummarys)
     */
    public static function getIncludeFilesDataFlows($fileSummary){
        if (!is_object($fileSummary)){
            return;
        }
        //1.得到include files
        $includeFiles = $fileSummary->getIncludeMap();
        $currentFilePath = $fileSummary->getPath();
        //2.foreach() files
        $retFileSummary = array();
        foreach ($includeFiles as $rpath){
            $absPath = FileUtils::getAbsPath($currentFilePath, $rpath);
            if (!$absPath){
                continue;
            }
            //  查看是否在fileSummaryContext中
            //  得到DataFlows
            $fileSummaryContext = FileSummaryContext::getInstance();           
            $ret = $fileSummaryContext->findSummaryByPath($absPath);
            if (is_object($ret)){
                //查看此文件是否有include文件
                $pRetFiles = self::getIncludeFilesDataFlows($ret);
                $retFileSummary = array_merge($pRetFiles, $retFileSummary);
                $retFileSummary = array_merge(array($ret), $retFileSummary);
            }else{
                $includeFileSummary = self::getFileSummary($absPath);
                if (is_object($includeFileSummary)){
                    $pRetFiles = self::getIncludeFilesDataFlows($includeFileSummary);
                    $retFileSummary = array_merge($pRetFiles, $retFileSummary);
                    $retFileSummary = array_merge(array($includeFileSummary), $retFileSummary);
                }
                
            }
        }
        //return all files summary
        //include在前的，在数组前面
        return $retFileSummary;
    }
    
    /**
     * 得到一个文件基本信息FileSummary，包括路径,基本块，包含的文件等
     * @param string $absPath
     */
	public static function getFileSummary($absPath){
	    if (!$absPath){
	        return ;
		}
	    $visitor = new MyVisitor() ;
	    $parser = new PhpParser\Parser(new PhpParser\Lexer\Emulative) ;
	    $traverser = new PhpParser\NodeTraverser ;
	    $code = file_get_contents($absPath);
	    try {
	        $stmts = $parser->parse($code);
	    } catch (Exception $e) {
	        return ;
	    }
	    
	    $traverser->addVisitor($visitor) ;
	    $traverser->traverse($stmts) ;
	    $nodes = $visitor->getNodes() ;
	    
	    $fileSummary = new FileSummary();
	    $fileSummary->setPath($absPath);
	    
	    $currBlock = new BasicBlock() ;
	    foreach ($nodes as $node){
	        if(!is_object($node)) continue ;
	        	
	        //不分析函数定义
	        if($node->getType() == "Stmt_Function"){
	            continue ;
	        }
	        $currBlock->addNode($node);
	    }
	    
	    
	    $fileSummaryGenerator = new FileSummaryGenerator();
	    $fileSummaryGenerator->simulate($currBlock, $fileSummary);
	    return $fileSummary;
	}
	
	/**
	 * 得到该文件的dataFlows
	 * @param Nodes $nodes
	 */
	public function simulate($block, $fileSummary){
	    $nodes = $block->getContainedNodes();
	    //循环nodes集合，搜集信息加入到中
	    foreach ($nodes as $node){
	        //搜集节点中的require include require_once include_once的PHP文件名称
	        $fileSummary->addIncludeToMap(NodeUtils::getNodeIncludeInfo($node)) ;
	        
	        switch ($node->getType()){
	            //处理赋值语句
	            case 'Expr_Assign':
	                $dataFlow = new DataFlow() ;
	                $this->assignHandler($node, $dataFlow, "left", $block, $fileSummary) ;
	                $this->assignHandler($node, $dataFlow, "right", $block, $fileSummary) ;
	                //处理完一条赋值语句，加入DataFlowMap
	                $fileSummary->addDataFlow($dataFlow);
	                $block->getBlockSummary()->addDataFlowItem($dataFlow);
	                break ;
	    
	            //处理字符串连接赋值
	            //$sql .= "from users where"生成sql => "from users where"
	            case 'Expr_AssignOp_Concat':
	                $dataFlow = new DataFlow() ;
	                $this->assignConcatHandler($node, $dataFlow, "left", $block, $fileSummary) ;
	                $this->assignConcatHandler($node, $dataFlow, "right", $block, $fileSummary) ;
	                //处理完一条赋值语句，加入DataFlowMap
	                $fileSummary->addDataFlow($dataFlow);
	                $block->getBlockSummary()->addDataFlowItem($dataFlow);
	                break ;
	            
	           default:
	               break;
	        }
	    }
	} 
	
	/**
	 * 处理赋值的assign语句，添加至dataFlows中
	 * @param AST $node
	 * @param DataFlow $dataFlow
	 * @param string $type
	 */
	public function assignHandler($node, $dataFlow, $type, $block, $fileSummary){
	    $part = null ;
	    if($type == "left"){
	        $part = $node->var ;
	    }else if($type == "right"){
	        $part = $node->expr ;
	    }else{
	        return ;
	    }
	    
	    //处理$GLOBALS的赋值
	    //$GLOBAL['name'] = "chongrui" ; 数据流信息为 $name = "chongrui" ;
	    if ($part && SymbolUtils::isArrayDimFetch($part) && (substr(NodeUtils::getNodeStringName($part),0,7)=="GLOBALS")){
	        //加入dataFlow
	        $arr = new ArrayDimFetchSymbol() ;
	        $arr->setValue($part) ;
	        if($type == "left"){
	            $dataFlow->setLocation($arr) ;
	            $dataFlow->setName(NodeUtils::getNodeGLOBALSNodeName($part)) ;
	        }else if($type == "right"){
	            $dataFlow->setValue($arr) ;
	        }
	        return ;
	    }
	    
	    
	    //处理赋值语句，存放在DataFlow
	    //处理赋值语句的左边
	    if($part && SymbolUtils::isValue($part)){
	        //在DataFlow加入Location以及name
	        $vs = new ValueSymbol() ;
	        $vs->setValueByNode($part) ;
	        if($type == "left"){
	            $dataFlow->setLocation($vs) ;
	            $dataFlow->setName($part->name) ;
	        }else if($type == "right"){
	            $dataFlow->setValue($vs) ;
	        }
	    
	    }elseif ($part && SymbolUtils::isVariable($part)){
	        	
	        //加入dataFlow
	        $vars = new VariableSymbol() ;
	        $vars->setValue($part);
	        if($type == "left"){
	            $dataFlow->setLocation($vars) ;
	            $dataFlow->setName($part->name) ;
	        }else if($type == "right"){
	            $dataFlow->setValue($part) ;
	        }
	        	
	    }elseif ($part && SymbolUtils::isConstant($part)){
	        	
	        //加入dataFlow
	        $con = new ConstantSymbol() ;
	        $con->setValueByNode($part) ;
	        $con->setName($part->name->parts[0]) ;
	        if($type == "left"){
	            $dataFlow->setLocation($con) ;
	            $dataFlow->setName($part->name) ;
	        }else if($type == "right"){
	            $dataFlow->setValue($con) ;
	        }
	    }elseif ($part && SymbolUtils::isArrayDimFetch($part)){
	        //加入dataFlow
	        $arr = new ArrayDimFetchSymbol() ;
	        $arr->setValue($part) ;
	        if($type == "left"){
	            $dataFlow->setLocation($arr) ;
	            $dataFlow->setName(NodeUtils::getNodeStringName($part)) ;
	        }else if($type == "right"){
	            $dataFlow->setValue($arr) ;
	        }
	    }elseif ($part && SymbolUtils::isConcat($part)){
	        $concat = new ConcatSymbol() ;
	        $concat->setItemByNode($part) ;
	        if($type == "left"){
	            $dataFlow->setLocation($concat) ;
	            $dataFlow->setName($part->name) ;
	        }else if($type == "right"){
	            $dataFlow->setValue($concat) ;
	        }
	    }else{
	        //不属于已有的任何一个symbol类型,如函数调用
            if($part && ($part->getType() == "Expr_FuncCall" || 
			    $part->getType() == "Expr_MethodCall" || 
			    $part->getType() == "Expr_StaticCall")){
                if($type == "left"){
    	            $dataFlow->setLocation($arr) ;
    	            $dataFlow->setName(NodeUtils::getNodeStringName($part)) ;
    	        }else if($type == "right"){
                    //处理净化信息和编码信息
                    SanitizationHandler::setSanitiInfo($part, $dataFlow, $block, $fileSummary) ;
                    EncodingHandler::setEncodeInfo($part, $dataFlow, $block, $fileSummary) ;
                }
	        }
	        //处理三元表达式
	        if($part && $part->getType() == "Expr_Ternary"){
	            BIFuncUtils::ternaryHandler($type, $part, $dataFlow) ;
	        }
	    }
	    
	}
	
	/**
	 * 处理赋值的concat语句，添加至dataFlows中
	 * @param AST $node
	 * @param DataFlow $dataFlow
	 * @param string $type
	 */
	private function assignConcatHandler($node, $dataFlow, $type, $block, $fileSummary){
	    $this->assignHandler($node, $dataFlow, $type, $block, $fileSummary) ;
	}
	
}



?>