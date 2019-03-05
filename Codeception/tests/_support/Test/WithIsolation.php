<?php
	namespace Test;
	
	/**
	 * Usage: add runIsolated annotation to either class or a test method.
	 * If added to class, a single isolated process will be used for all methods.
	 * 
	 * NOTE: PHPUnit does a trick of instantiating different objects for every
	 * method run, presumably for isolation purposes. When the class is run isolated,
	 * this feature is lost: all methods will be run on a single instance. 
	 * 
	 * @author Dinu Marina dinumarina@gmail.com
	 *
	 */
	class WithIsolation extends \Codeception\Test\Unit {
		private static $runners=[];
		private static $isSetup=[];
		private static $isIsolated_test=[];
		private $isolatedName;
		private static $amIsolated=false;
		protected $knownThrowables=[];
		
		/**
		 * Override this to provide initialisation code for the isolated container 
		 */
		function isolationSetup(){}
		
		
		function _before(){
			parent::_before();
			$class=get_called_class();
			if(!array_key_exists($class,self::$isSetup)){
				self::$isSetup[$class]=true;
				static::setupOnce();
			}
		}
		
		protected function runTest(){
			$class=get_called_class();
			$wrapper=null;
			if(self::$isIsolated_test[$class]){
				$wrapper='runTest_isolated';
			} 
			elseif(self::doRunIsolatedTestMethod($class,$this->getName(false))){
				$wrapper='runTestMethod_isolated';
			}
			if($wrapper){
				/**
				 * HACK: The parent uses $this->name to decide which method to call;
				 * It does not seem to use it before doing the actual call, so we
				 * might just hijack it here to trick it into calling runTest_isolated.
				 * This might change in the future (parent might decide to use $this->name
				 * for other purposes), so it's a bit unsafe. Alternative would be
				 * to copy parent code here, or ask author for a wrapper.
				 * @see \PHPUnit\Framework\TestCase::runTest()
				 */
				$this->isolatedName=$this->getName(false);
				$this->setName($wrapper);
			}
			return parent::runTest();
		}
		
		static function tearDownAfterClass(){
			parent::tearDownAfterClass();
			$class=get_called_class();
			if(
				self::$isIsolated_test[$class] &&
				/* HACK: This is called multiple times per class; see
				 * https://github.com/Codeception/Codeception/issues/5416
				 */
				self::$runners[$class]
			){
				static::stopRunner();
			}
		}
		
		protected function setupOnce(){
			$class=get_called_class();
			self::$isIsolated_test[$class]=self::doRunIsolatedTest($class);
			if(self::$isIsolated_test[$class]){
				$this->startRunner();
				$this->runIsolated('isolationSetup');
			}
		}
		
		protected function runTest_isolated(){
			$this->setName($this->isolatedName);
			$this->runIsolated($this->isolatedName,func_get_args());
		}
		
		protected function runTestMethod_isolated(){
			$this->setName($this->isolatedName);
			$this->runIsolatedSingle($this->isolatedName,func_get_args());
		}
		
		protected function startRunner(){
			$class=get_called_class();
			if(array_key_exists($class,self::$runners)){
				throw new \Codeception\Exception\Fail('A runner is already started');
			}
			$this->knownThrowables=[];
			foreach(get_declared_classes() as $dclass){
 			   if(
					is_a($dclass,'Error',true) ||
					is_a($dclass,'Exception',true)
 			   	){
					$this->knownThrowables[]=$dclass;
				}
			}
			$runner=[
				'isStarted'=>false,
				'commandPipe'=>null,
				'commandPipeFile'=>null,
				'resultPipe'=>null,
				'resultPipeFile'=>null,
				'pid'=>null,
				'isFailed'=>false
			];
			self::$runners[$class]=&$runner;
			$id=uniqid();
			$runner['commandPipeFile']='/tmp/runTest_command_'.$id;
			$runner['resultPipeFile']='/tmp/runTest_result_'.$id;
			if(!posix_mkfifo($runner['commandPipeFile'],0644)){
				throw new \Codeception\Exception\Fail('Cannot make fifo');
			}
			if(!posix_mkfifo($runner['resultPipeFile'],0644)){
				throw new \Codeception\Exception\Fail('Cannot make fifo');
			}
			$pid=pcntl_fork();
			if(!$pid){
				self::$amIsolated=true;
				register_shutdown_function(function() use (&$runner) {
					$error=error_get_last();
					if(in_array($error['type'], [E_ERROR, E_COMPILE_ERROR, E_CORE_ERROR])){
						$result=[
							'type'=>'fatal',
							'error'=>$error
						];
						fwrite($runner['resultPipe'],serialize($result));
						fclose($runner['resultPipe']);
						exit();
					}
				});
				$this->runner();
			}
			elseif($pid===-1){
				throw new \Codeception\Exception\Fail('Could not start runner');
			} else {
 				$runner['pid']=$pid;
				$runner['started']=true;
				$runner['commandPipe']=fopen($runner['commandPipeFile'],'w');
			}
		}
		
		protected function runner(){
			$class=get_called_class();
			$runner=&self::$runners[$class];
			do {
				try {
					$command_s=file_get_contents($runner['commandPipeFile']);
					if(!$command_s){
						break;
					}
					$command=unserialize($command_s);
					$runner['resultPipe']=fopen($runner['resultPipeFile'],'w');
					switch($command['command']){
						case 'exit':
							$result=[
								'type'=>'ok'
							];
							fwrite($runner['resultPipe'],serialize($result));
							fclose($runner['resultPipe']);
							break 2;
						case 'run':
							$toCall=$command['what'];
							$res=call_user_func_array([$this,$toCall],$command['args']);
							$result=[
								'type'=>'ok',
								'result'=>$res
							];
							break;
					}
					fwrite($runner['resultPipe'],serialize($result));
					fclose($runner['resultPipe']);
				} catch (\Throwable $t) {
					$flat=$this->flattenThrowable($t);
					$result=[
						'type'=>'exception',
						'exception'=>$flat
					];
					fwrite($runner['resultPipe'],serialize($result));
					fclose($runner['resultPipe']);
				}
			} while(true);
			/**
			 * Problem: This triggers Codeception's shutdown handler which tries
			 * to output some error about an incomplete run.
			 */
			die();
		}
		
		protected function runIsolated($callable,$args=[]){
			$class=get_called_class();
			if(!array_key_exists($class,self::$runners)){
				throw new \Codeception\Exception\Fail('A runner is not started');
			}
			$runner=&self::$runners[$class];
			
			if($runner['isFailed']){
				throw new \Codeception\Exception\Fail('Runner died with a previous command');
			}
			
			$command=[
				'command'=>'run',
				'what'=>$callable,
				'args'=>$args
			];
			fwrite($runner['commandPipe'],serialize($command));
			fclose($runner['commandPipe']);
			$result_s=file_get_contents($runner['resultPipeFile']);
			if(!$result_s){
				$this->killRunner();
				throw new \Codeception\Exception\Fail('Could not retrieve runner results');
			}
			$result=unserialize($result_s);
			if($result['type']!=='fatal'){
				$runner['commandPipe']=fopen($runner['commandPipeFile'],'w');
			}
			switch($result['type']){
				case 'exception':
					$exception=static::unflattenThrowable($result['exception']);
					throw $exception;
				case 'ok':
					return $result['result'];
				case 'fatal':
					$runner['isFailed']=true;
					$exception=new \RuntimeException('Fatal error in runner: '.$result['error']['message'].' in '.$result['error']['file'].':'.$result['error']['line']);
					throw $exception;
			}
		}
		
		protected static function stopRunner(){
			$class=get_called_class();
			if(!array_key_exists($class,self::$runners)){
				throw new \Codeception\Exception\Fail('A runner is not started');
			}
			$runner=self::$runners[$class];
			
			$command=[
				'command'=>'exit'
			];
			fwrite($runner['commandPipe'],serialize($command));
			fclose($runner['commandPipe']);
			$result_s=file_get_contents($runner['resultPipeFile']);
			if(!$result_s){
				static::killRunner();
				throw new \Codeception\Exception\Incomplete('Could not stop runner');
			}
			$result=unserialize($result_s);
			if($result['type']!=='ok'){
				static::killRunner();
				throw new \Codeception\Exception\Incomplete('Could not stop runner');
			}
 			pcntl_waitpid($runner['pid'],$status);
			unlink($runner['commandPipeFile']);
			unlink($runner['resultPipeFile']);
			unset(self::$runners[$class]);
		}
		
		protected static function killRunner(){
			$class=get_called_class();
			if(!array_key_exists($class,self::$runners)){
				throw new \Codeception\Exception\Fail('A runner is not started');
			}
			$runner=self::$runners[$class];
			posix_kill($runner['pid'],SIGKILL);
			unlink($runner['commandPipeFile']);
			unlink($runner['resultPipeFile']);
			unset(self::$runners[$class]);
		}
		
		protected function runIsolatedSingle($callable,$args=[]){
			$this->startRunner();
			$this->runIsolated('isolationSetup');
			$this->runIsolated($callable,$args);
			static::stopRunner();
		}

		private static function doRunIsolatedTestMethod($class,$methodName){
	        $annotations = \PHPUnit\Util\Test::parseTestMethodAnnotations(
	            $class,
	            $methodName
	        );
	        return isset($annotations['method']['runIsolated']);
		}
		
		private static function doRunIsolatedTest($class){
	        $annotations = \PHPUnit\Util\Test::parseTestMethodAnnotations(
	            $class,
	            null
	        );
	        return isset($annotations['class']['runIsolated']);
		}
		
		/**
		 * Throwables cannot be serialized if they have callbacks in args. So
		 * flatten those callbacks.
		 */
		private function flattenThrowable($throwable) {
	        $flatten = function(&$value, $key) {
	            if ($value instanceof \Closure) {
	                $closureReflection = new \ReflectionFunction($value);
	                $value = sprintf(
	                    '(Closure at %s:%s)',
	                    $closureReflection->getFileName(),
	                    $closureReflection->getStartLine()
	                );
	            }
	            elseif(!is_array($value)){
	            	try{
	            		serialize($value);
	            	} catch (\Throwable $e){
	            		$value='(Unserializable '.get_class($value).')';
	            	}
	            }
	        };
	        
	        $struct=[];
			
			$class=get_class($throwable);
			$struct['class']=$class;
			$refClass=new \ReflectionClass($class);
			foreach($refClass->getProperties() as $refProperty){
				$refProperty->setAccessible(true);
				$value=$refProperty->getValue($throwable);
				$refProperty->setAccessible(false);
				if(is_array($value)){
					array_walk_recursive($value, $flatten);
				}
				$struct['data'][$refProperty->getName()]=$value;
			}
			$privateClass=get_parent_class($class);
			while($privateClass){
				$refPrivateClass=new \ReflectionClass($privateClass);
				foreach($refPrivateClass->getProperties(\ReflectionProperty::IS_PRIVATE) as $refProperty){
					$refProperty->setAccessible(true);
					$value=$refProperty->getValue($throwable);
					$refProperty->setAccessible(false);
					if(is_array($value)){
						array_walk_recursive($value, $flatten);
					}
					$struct['data'][$refProperty->getName()]=$value;
				}
				$privateClass=get_parent_class($privateClass);
			}
			$refClass=($throwable instanceof \Error)?'Error':'Exception';
	        $previousProperty = (new \ReflectionClass($refClass))->getProperty('previous');
	        $previousProperty->setAccessible(true);
	        $previous=$previousProperty->getValue($throwable);
	        $previousProperty->setAccessible(false);
	        if($previous){
	        	$struct['previous']=$this->flattenThrowable($previous);
	        }
	        return $struct;
	    }
	    
	    private static function unflattenThrowable ($struct){
	    	$class=$struct['class'];
	    	do {
	    		if(class_exists($class)){
	    			break;
	    		}
	    		$class=get_parent_class($class);
	    	} while ($class);
	    	if(
	    		!$class ||
	    		(
	    			!is_a($class,'Exception',true) &&
	    			!is_a($class,'Error',true)
	    		)
	    	){
	    		$throwable=new \RuntimeException('Unknown error: cannot unflatten original error');
	    	} else {
		    	$throwable=new $class();
	    	}
			$refClass=new \ReflectionClass($class);
			foreach($refClass->getProperties() as $refProperty){
				$name=$refProperty->getName();
				if($name!=='previous'){
					$refProperty->setAccessible(true);
					$refProperty->setValue($throwable,$struct['data'][$refProperty->getName()]);
					$refProperty->setAccessible(false);
				}
			}
			$privateClass=get_parent_class($class);
			while($privateClass){
				$refPrivateClass=new \ReflectionClass($privateClass);
				foreach($refPrivateClass->getProperties(\ReflectionProperty::IS_PRIVATE) as $refProperty){
					$name=$refProperty->getName();
					if($name!=='previous'){
						$refProperty->setAccessible(true);
						$refProperty->setValue($throwable,$struct['data'][$refProperty->getName()]);
						$refProperty->setAccessible(false);
					}
				}
				$privateClass=get_parent_class($privateClass);
			}
			if($struct['previous']){
				$previous=static::unflattenThrowable($struct['previous']);
				$refClass=($throwable instanceof \Error)?'Error':'Exception';
		        $previousProperty = (new \ReflectionClass($refClass))->getProperty('previous');
		        $previousProperty->setAccessible(true);
		        $previousProperty->setValue($throwable,$previous);
	       		$previousProperty->setAccessible(false);
			}
	        return $throwable;
		}
	}