# WithIsolation

## Process isolation test runner for Codeception/PHPUnit

This is a project to create a test runner for Codeception and PHPUnit unit 
tests that provides separate process isolation for running tests and test 
cases.

Unlike the `@runTestInSeparateProcess` and `@runTestsInSeparateProcesses` that are
built into PHPUnit: 
- our solution is built on top of the testing frameworks, extending test cases
- we have a simpler approach based on forking the current running process 
rather than building up new test environments from scratch for the spawned
processes

In the current implementation, our project uses POSIX functions for IPC, so it
will only run on Linux.

## Usage

### Codeception

- Copy the test case class `WithIsolation.php` into the `tests/_support/Test`
directory of your testing environment.
- Rewrite your existing test cases (in tests/unit or where your unit test 
suite(s) are located) to extend `\Test\WithIsolation` instead of 
\Codeception\Test\Unit .
- Add annotations: the `@runIsolated` annotation can be added either to the test
case class or to the test methods (function test*):
  - When `@runIsolated` is added to the test case class, the isolated process is
  forked upon running the first test in the case, and all tests are run in the
  same process, meaning the tests share the same global state (loaded classes,
  functions). The `@backupGlobals`, `@backupStaticAttributes` PHPUnit annotations
  are circumvented (and thus useless). The whole point of running a set of 
  tests in a separate process is to watch the functioning of a global-heavy
  component, like a framework's bootstrap/shutdown sequence, that should run
  unalterated by the testing tool. On the other hand, `@preserveGlobalState` is 
  implied (except on the first test function), because in the spawned process
  nothing is touched between function runs.
  
    Note this is different than PHPUnit's `@runTestsInSeparateProcesses` that was
    just a shorthand for `@runTestInSeparateProcess` for each test. Here all the
    tests are run in a single isolated process, not each in its own (see below
    use case).
  
    When `@runIsolated` is set on the entire test case class, the same annotation
    should not be used on separate tests and will be ignored.
  
  - When `@runIsolated` is added to a test function, that test is run in an
  isolated process. Each isolated function is run in a separate process spawned
  just for it. This is still done in sequential order.
  
- Implement the `isolationSetup()` function if needed: this function is run in
the spawned process before the test function(s), so you can initialize the 
isolated process state in this function.
  
## Notes

The spawned processes are terminated as the test or test class is done running.
Termination is done via die(). This means that any registered shutdown handlers
before or during running of the script will get run when the process exits.

For example, codeception's finish handlers get called which display a notice 
that all test did not run (in the spawned process). This output is, however,
generally hidden and you won't see it in the run results. There are a few 
circumstances where this can perspire, we're not sure yet which.

If you are using Xdebug (even having it enabled), you will get "broken pipe"
notifications before Xdebug 2.7 and debugging is unreliable with forking. This
looks fixed in Xdebg 2.7 beta.