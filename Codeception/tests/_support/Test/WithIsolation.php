<?php
	namespace Test;

	class WithIsolation extends \Codeception\Test\Unit {
		private static $isSetup=[];
		private static $isIsolated_test=[];
		private static $runners=[];
		private static $amIsolated=false;
		
		function isolationSetup(){
		}
		
		function cc_isolationSetup(){
			//	setUp initializes $tester in Codeception
			$this->setUp();
			$this->isolationSetup();
			$this->tearDown();
		}
		
		function isolationTeardown(){
		}
		
		function cc_isolationTeardown(){
			//	setUp initializes $tester in Codeception
			$this->setUp();
			$this->isolationTeardown();
			$this->tearDown();
		}
		
		static final function setUpBeforeClass(){
		}

		static final function tearDownAfterClass(){
			$class=get_called_class();
			if(
				!self::$amIsolated &&
				self::$isIsolated_test[$class] &&
				/* HACK: This is called multiple times per class; see
				 * https://github.com/Codeception/Codeception/issues/5416
				 */
				self::$runners[$class]
			){
				static::stopRunner();
			}
		}
		
		public function run(\PHPUnit\Framework\TestResult $result = null): \PHPUnit\Framework\TestResult {
			if(self::$amIsolated){
				return parent::run($result);
			}
			$class=get_called_class();
			if(!array_key_exists($class,self::$isSetup)){
				static::setupOnce();
				self::$isSetup[$class]=true;
			}
			if(
				!self::$isIsolated_test[$class] &&
				!static::doRunIsolatedTestMethod($class,$this->getName(false))
			){
				return parent::run($result);
			}
	        if ($result === null) {
	            $result = $this->createResult();
	        }
	        if (!$this instanceof \PHPUnit\Framework\WarningTestCase) {
	            $this->setTestResultObject($result);
	            $this->setUseErrorHandlerFromAnnotation();
	        }
	
	        if ($this->useErrorHandler !== null) {
	            $oldErrorHandlerSetting = $result->getConvertErrorsToExceptions();
	            $result->convertErrorsToExceptions($this->useErrorHandler);
	        }
	
	        if (
	        	!$this instanceof \PHPUnit\Framework\WarningTestCase &&
	            !$this instanceof \PHPUnit\Framework\SkippedTestCase &&
	            !$this->handleDependencies()
	        ) {
	            return $result;
	        }
	        $args=[
	        	'isStrictAboutTestsThatDoNotTestAnything'=>$result->isStrictAboutTestsThatDoNotTestAnything(),
	        	'isStrictAboutOutputDuringTests'=>$result->isStrictAboutOutputDuringTests(),
	        	'enforcesTimeLimit'=>$result->enforcesTimeLimit(),
	        	'isStrictAboutTodoAnnotatedTests'=>$result->isStrictAboutTodoAnnotatedTests(),
	        	'isStrictAboutResourceUsageDuringSmallTests'=>$result->isStrictAboutResourceUsageDuringSmallTests(),
	        	'data'=>$this->data(),
	        	'dataName'=>$this->dataName(),
	        	'dependencyInput'=>$this->getDependencyInput()
	        ];
            if ($result->getCodeCoverage()) {
            	$args['collectCodeCoverageInformation']=true;
                $args['codeCoverageFilter'] = $result->getCodeCoverage()->filter();
            } else {
            	$args['collectCodeCoverageInformation']=false;
                $args['codeCoverageFilter'] = null;
            }
        	$result->startTest($this);
			if(!self::$isIsolated_test[$class]){
				static::startRunner();
			}
            $childResult=static::runIsolatedTestMethod($class,$this->getName(false),$args);
			if(!self::$isIsolated_test[$class]){
				static::stopRunner();
			}
			if (!empty($childResult['output'])) {
				$output = $childResult['output'];
			}

			/* @var \PHPUnit\Framework\TestCase $test */

			$this->setResult($childResult['testResult']);
			$this->addToAssertionCount($childResult['numAssertions']);

			/** @var TestResult $childResult */
			$childResult = $childResult['result'];

			if ($result->getCollectCodeCoverageInformation()) {
				$result->getCodeCoverage()->merge(
					$childResult->getCodeCoverage()
				);
			}

			$time           = $childResult->time();
			$notImplemented = $childResult->notImplemented();
			$risky          = $childResult->risky();
			$skipped        = $childResult->skipped();
			$errors         = $childResult->errors();
			$warnings       = $childResult->warnings();
			$failures       = $childResult->failures();

			if (!empty($notImplemented)) {
				$result->addError(
					$this,
					\PHPUnit\Util\PHP\AbstractPhpProcess::getException($notImplemented[0]),
					$time
				);
			} elseif (!empty($risky)) {
				$result->addError(
					$this,
					\PHPUnit\Util\PHP\AbstractPhpProcess::getException($risky[0]),
					$time
				);
			} elseif (!empty($skipped)) {
				$result->addError(
					$this,
					\PHPUnit\Util\PHP\AbstractPhpProcess::getException($skipped[0]),
					$time
				);
			} elseif (!empty($errors)) {
				$result->addError(
					$this,
					\PHPUnit\Util\PHP\AbstractPhpProcess::getException($errors[0]),
					$time
				);
			} elseif (!empty($warnings)) {
				$result->addWarning(
					$this,
					\PHPUnit\Util\PHP\AbstractPhpProcess::getException($warnings[0]),
					$time
				);
			} elseif (!empty($failures)) {
				$result->addFailure(
					$this,
					\PHPUnit\Util\PHP\AbstractPhpProcess::getException($failures[0]),
					$time
				);
			}
	        $result->endTest($this, $time);
	
	        if (!empty($output)) {
	            print $output;
	        }
			
			if (isset($oldErrorHandlerSetting)) {
	            $result->convertErrorsToExceptions($oldErrorHandlerSetting);
	        }
	        $this->result = null;
	        return $result;
		}
		
		protected function setupOnce(){
			$class=get_called_class();
			self::$isIsolated_test[$class]=self::doRunIsolatedTest($class);
			if(self::$isIsolated_test[$class]){
				$this->startRunner();
				$this->runIsolated([$class,'cc_isolationSetup']);
			}
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
			do {
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
						if(!self::$isIsolated_test[$class]){
							$this->stopRunner();
						}
						throw $result['exception'];
					case 'ok':
						return $result['result'];
					case 'fatal':
						$runner['isFailed']=true;
						$exception=new \RuntimeException('Fatal error in runner: '.$result['error']['message'].' in '.$result['error']['file'].':'.$result['error']['line']);
						throw $exception;
					case 'run':
						$res=$result['callback'](...$result['arguments']);
						$return=[
							'type'=>'ok',
							'result'=>$res
						];
						fwrite($runner['commandPipe'],serialize($return));
						fclose($runner['commandPipe']);
						break;
				}
			} while ( true );
		}
		
		protected function runIsolatedTestMethod($class,$method,$args){
			$class=get_called_class();
			if(!array_key_exists($class,self::$runners)){
				throw new \Codeception\Exception\Fail('A runner is not started');
			}
			$runner=&self::$runners[$class];
			
			if($runner['isFailed']){
				throw new \Codeception\Exception\Fail('Runner died with a previous command');
			}
			
			$command=[
				'command'=>'runTestMethod',
				'method'=>$method,
				'args'=>$args
			];
			fwrite($runner['commandPipe'],serialize($command));
			fclose($runner['commandPipe']);
			do {
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
						if(!self::$isIsolated_test[$class]){
							$this->stopRunner();
						}
						throw $result['exception'];
					case 'ok':
						return $result['result'];
					case 'fatal':
						$runner['isFailed']=true;
						$exception=new \RuntimeException('Fatal error in runner: '.$result['error']['message'].' in '.$result['error']['file'].':'.$result['error']['line']);
						throw $exception;
					case 'run':
						$res=$result['callback'](...$result['arguments']);
						$return=[
							'type'=>'ok',
							'result'=>$res
						];
						fwrite($runner['commandPipe'],serialize($return));
						fclose($runner['commandPipe']);
						break;
				}
			} while ( true );
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
							if(
								is_array($toCall) &&
								$toCall[0]==$class
							){
								$toCall[0]=$this;
							}
							$res=call_user_func_array($toCall,$command['args']);
							$result=[
								'type'=>'ok',
								'result'=>$res
							];
							break;
						case 'runTestMethod':
    						$result = new \PHPUnit\Framework\TestResult;
						    if ($command['args']['collectCodeCoverageInformation']) {
						        $result->setCodeCoverage(
						            new \SebastianBergmann\CodeCoverage\CodeCoverage(
						                null,
						                unserialize($command['args']['codeCoverageFilter'])
						            )
						        );
						    }
						    $result->beStrictAboutTestsThatDoNotTestAnything($command['args']['isStrictAboutTestsThatDoNotTestAnything']);
						    $result->beStrictAboutOutputDuringTests($command['args']['isStrictAboutOutputDuringTests']);
						    $result->enforceTimeLimit($command['args']['enforcesTimeLimit']);
						    $result->beStrictAboutTodoAnnotatedTests($command['args']['isStrictAboutTodoAnnotatedTests']);
						    $result->beStrictAboutResourceUsageDuringSmallTests($command['args']['isStrictAboutResourceUsageDuringSmallTests']);
						    $this->setDependencyInput($command['args']['dependencyInput']);
						    $this->setInIsolation(true);
						    $this->run($result);
						    $output = '';
						    if (!$this->hasExpectationOnOutput()) {
						        $output = $this->getActualOutput();
						    }
						
							$result=[
								'type'=>'ok',
								'result'=>[
									'testResult'    => $this->getResult(),
									'numAssertions' => $this->getNumAssertions(),
									'result'        => $result,
									'output'        => $output
								]
							];
						    break;
					}
					fwrite($runner['resultPipe'],serialize($result));
					fclose($runner['resultPipe']);
				} catch (\Throwable $t) {
					if(!is_a('PHPUnit\Framework\ExceptionWrapper',$t)){
						$t=new \PHPUnit\Framework\ExceptionWrapper($t);
					}
					$result=[
						'type'=>'exception',
						'exception'=>$t
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
		
		protected static function stopRunner(){
			$class=get_called_class();
			if(!array_key_exists($class,self::$runners)){
				throw new \Codeception\Exception\Fail('A runner is not started');
			}
			$runner=&self::$runners[$class];
			
			if(self::$isIsolated_test[$class]){
				static::runIsolated([$class,'cc_isolationTeardown']);
			}
			
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
		
		public function runMain($callback,...$arguments){
			if(self::$amIsolated){
				$class=get_called_class();
				$runner=&self::$runners[$class];
				$result=[
					'type'=>'run',
					'callback'=>$callback,
					'arguments'=>$arguments
				];
				fwrite($runner['resultPipe'],serialize($result));
				fclose($runner['resultPipe']);
				$result_s=file_get_contents($runner['commandPipeFile']);
				if(!$result_s){
					die();
				}
				$result=unserialize($result_s);
				if($result['type']!=='ok'){
					die();
				}
				$runner['resultPipe']=fopen($runner['resultPipeFile'],'w');
				return $result['result'];
			} else {
				return $callback(...$arguments);
			}
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
		
		function isIsolated(){
			return self::$amIsolated;
		}
	}
	
	class FlattenedThrowable extends \PHPUnit\Framework\ExceptionWrapper {
		public $_s_class;
		function __construct(\Throwable $throwable){
			$class=get_class($throwable);
			$this->_s_class=$class;
			$refClass=new \ReflectionClass($class);
			foreach($refClass->getProperties() as $refProperty){
				$refProperty->setAccessible(true);
				$value=$refProperty->getValue($throwable);
				$refProperty->setAccessible(false);
				$value=self::flattenVar($value);
				$refProperty->setAccessible(true);
				$value=$refProperty->setValue($this);
				$refProperty->setAccessible(false);
			}
			$privateClass=get_parent_class($class);
			while($privateClass){
				$refPrivateClass=new \ReflectionClass($privateClass);
				foreach($refPrivateClass->getProperties(\ReflectionProperty::IS_PRIVATE) as $refProperty){
					$refProperty->setAccessible(true);
					$value=$refProperty->getValue($throwable);
					$refProperty->setAccessible(false);
					$value=self::flattenVar($value);
					$refProperty->setAccessible(true);
					$value=$refProperty->setValue($this);
					$refProperty->setAccessible(false);
				}
				$privateClass=get_parent_class($privateClass);
			}
		}
		
		function __toString(): string {
	    	$class=$this->_s_class;
	    	do {
	    		if(class_exists($class)){
	    			break;
	    		}
	    		$class=get_parent_class($class);
	    	} while ($class);
			return $class::__toString();
		}
		
		function getSerializableTrace(): array {
			return $this->getTrace();
		}
		
	    public function getClassName(): string
	    {
	        return $this->_s_class;
	    }
	}	