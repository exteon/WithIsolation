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
				static::startRunner();
				static::runIsolated('isolationSetup');
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
			
			$runner=[
				'isStarted'=>false,
				'commandPipe'=>null,
				'commandPipeFile'=>null,
				'resultPipe'=>null,
				'resultPipeFile'=>null,
				'pid'=>null
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
				do {
					try {
						$command_s=file_get_contents($runner['commandPipeFile']);
						if(!$command_s){
							break;
						}
						$command=unserialize($command_s);
						$resultPipe=fopen($runner['resultPipeFile'],'w');
						switch($command['command']){
							case 'exit':
								$result=[
									'type'=>'ok'
								];
								fwrite($resultPipe,serialize($result));
								fclose($resultPipe);
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
						fwrite($resultPipe,serialize($result));
						fclose($resultPipe);
					} catch (\Throwable $t) {
						self::flattenThrowableBacktrace($t);
						$result=[
							'type'=>'exception',
							'exception'=>serialize($t)
						];
						fwrite($resultPipe,serialize($result));
						fclose($resultPipe);
					}
				} while(true);
				/**
				 * Problem: This triggers Codeception's shutdown handler which tries
				 * to output some error about an incomplete run.
				 */
				die();
			}
			elseif($pid===-1){
				throw new \Codeception\Exception\Fail('Could not start runner');
			} else {
 				$runner['pid']=$pid;
				$runner['started']=true;
				$runner['commandPipe']=fopen($runner['commandPipeFile'],'w');
			}
		}
		
		protected function runIsolated($callable,$args=[]){
			$class=get_called_class();
			if(!array_key_exists($class,self::$runners)){
				throw new \Codeception\Exception\Fail('A runner is not started');
			}
			$runner=&self::$runners[$class];
			
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
			$runner['commandPipe']=fopen($runner['commandPipeFile'],'w');
			$result=unserialize($result_s);
			switch($result['type']){
				case 'exception':
					$exception=unserialize($result['exception']);
					throw $exception;
				case 'ok':
					return $result['result'];
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
		 * 
		 * @author https://stackoverflow.com/users/181664/artur-bodera
		 * @see https://gist.github.com/Thinkscape/805ba8b91cdce6bcaf7c
		 */
		private static function flattenThrowableBacktrace(\Throwable $throwable) {
			$refClass=($throwable instanceof \Error)?'Error':'Exception';
	        $traceProperty = (new \ReflectionClass('Error'))->getProperty('trace');
	        $traceProperty->setAccessible(true);
	        $flatten = function(&$value, $key) {
	            if ($value instanceof \Closure) {
	                $closureReflection = new \ReflectionFunction($value);
	                $value = sprintf(
	                    '(Closure at %s:%s)',
	                    $closureReflection->getFileName(),
	                    $closureReflection->getStartLine()
	                );
	            } elseif (is_object($value)) {
	                $value = sprintf('object(%s)', get_class($value));
	            } elseif (is_resource($value)) {
	                $value = sprintf('resource(%s)', get_resource_type($value));
	            }
	        };
	        do {
	            $trace = $traceProperty->getValue($throwable);
	            foreach($trace as &$call) {
	                array_walk_recursive($call['args'], $flatten);
	            }
	            $traceProperty->setValue($throwable, $trace);
	        } while($throwable = $throwable->getPrevious());
	        $traceProperty->setAccessible(false);
	    }
		
	}