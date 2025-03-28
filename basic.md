# PHP True Async

## Proposal

### Overview

This **RFC** describes the **API** and **new syntax** for writing concurrent code in PHP, which includes:

#### Launching any function in non-blocking mode:

```php 
function myFunction(): void 
{
    echo "Hello, World!\n";
}

spawn myFunction();
sleep(1);
echo "Next line\n";
```

Output:

```
Hello, World!
Next line
```

#### Non-blocking versions of built-in PHP functions:
```php
   spawn {
        $result = file_get_contents("file.txt");
        
        if($result === false) {
            echo "Error reading file.txt\n";
        }
        
        echo "File content: $result\n";
   };

   echo "Next line\n";
```

Output:

```
Next line
File content: ...
```

#### Waiting for coroutine results

```php
echo await spawn file_get_contents("file.txt");
```

#### Awaiting a result with cancellation

```php
echo await spawn file_get_contents("https://php.net/") until spawn sleep(2);
```

#### Suspend keyword

Transferring control from the coroutine to the `Scheduler`:

```php
function myFunction(): void {
    echo "Hello, World!\n";
    suspend;
    echo "Goodbye, World!\n";
}

spawn myFunction();
echo "Next line\n";
```

Output:

```
Hello, World
Next line
Goodbye, World
```

#### Working with a group of concurrent tasks.

```php
function mergeFiles(string ...$files): string
{
   async $tasks {
       foreach ($files as $file) {
           spawn file_get_contents($file);
       }
       
       return array_merge("\n", await $tasks->directTasks());
   }
}
```

#### Structured concurrency

```php
function loadDashboardData(string $userId): array 
{
    async $dashboardScope {
    
        spawn fetchUserProfile($userId);
        spawn fetchUserNotifications($userId);
        spawn fetchRecentActivity($userId);
        
        try {
            [$profile, $notifications, $activity] = await $dashboardScope->allTasks();
            
            return [
                'profile' => $profile,
                'notifications' => $notifications,
                'activity' => $activity,
            ];
        } catch (\Exception $e) {
            logError("Dashboard loading failed", $e);
            return ['error' => $e->getMessage()];
        }
    }
}

function fetchUserSettings(string $userId): array 
{
    // ...
    // This exception stops all tasks in the hierarchy that were created as part of the request.
    throw new Exception("Error fetching customers");
}

function fetchUserProfile(string $userId): array 
{
    async inherit $userDataScope {        
        spawn fetchUserData();
        spawn fetchUserSettings($userId);              
        
        [$userData, $settings] = await $dashboardScope->allTasks();
        
        $userData['settings'] = $settings ?? [];
       
        return $userData;
    }
}

spawn loadDashboardData($userId);
```

```text
loadDashboardData()  ← async $dashboardScope
├── fetchUserProfile()  ← async inherit $userDataScope
│   ├── spawn fetchUserData()
│   └── spawn fetchUserSettings()
│       ├── throw new Exception(...) ← ❗can stop all tasks in the hierarchy
├── spawn getUserOrders()
└── spawn getRecommendations()
```

#### Await all child tasks.

```php
function processBackgroundJobs(string ...$jobs): array
{
    $scope = new Scope();
    
    foreach ($jobs as $job) {
        spawn with $scope processJob($job);
    }
    
    // Waiting for all child tasks throughout the entire depth of the hierarchy.
    await $scope->allTasks();
}

function processJob(mixed $job): void {
    async inherit $jobScope {
       spawn task1($job);
       spawn task2($job);    
    }
}
```

#### Await only direct child tasks.

```php
function mergeFiles(string ...$files): string
{
   async $tasks {
       foreach ($files as $file) {
           spawn file_get_contents($file); // <- direct child task
       }
       
       try {
          $results = await $tasks->directTasks();
       } finally {
          // cancel all remaining tasks
          $scope->disposeAfterTimeout(5000);
       }
       
       return array_merge("\n", $results);
   }
}
```

### Scheduler and Reactor

The **Scheduler** and **Reactor** components are part of the low-level implementation of this **RFC**.

The **Scheduler** is a component responsible for managing the execution order of coroutines.

> ⚠️ **Warning:** Users should not make assumptions about the execution order of coroutines unless
> this is a specific goal of a particular **Scheduler** implementation.

The **Reactor** is a component that implements the **Event Loop**.
It may be exposed as a separate API in **PHP-land**,
but its behavior is not defined within this **RFC**.

### Limitations

This **RFC** does not implement "colored functions"
(see: https://journal.stuffwithstuff.com/2015/02/01/what-color-is-your-function/).
Instead, it provides **transparent concurrency**, allowing **any function** to be asynchronous.

This **RFC** does not contradict a potential multitasking implementation
where possible but does not assume its existence.

This **RFC** assumes the ability to create coroutines in other **Threads** using the **Scheduler API**
or separate extensions but does not describe this capability.

This **RFC** also assumes functionality expansion using **SharedMemory**,
specifically designed shared memory objects, through a separate API that is not part of this **RFC**.

### Namespace

All functions, classes, and constants defined in this **RFC** are located in the `Async` namespace.
Extensions for **Scheduler/Reactor** are allowed to extend this namespace with functions and classes,
provided that they are directly related to concurrency functionality.

### Coroutine

> A `Coroutine` is an `execution container`, transparent to the code,
> that can be suspended on demand and resumed at any time.

Isolated execution contexts make it possible to switch between coroutines and execute tasks concurrently.

Any function can be executed as a coroutine without any changes to the code.

A coroutine can stop itself bypassing control to the `Scheduler`.
However, it cannot be stopped externally.

A suspended coroutine can be resumed at any time.
The `Scheduler` component is responsible for the coroutine resumption algorithm.

A coroutine can be resumed with an **exception**, in which case an exception
will be thrown from the suspension point.

### Spawn expression

To create coroutines, the `spawn <callable>` expression is used.
It launches the `<callable>` in a separate execution context and returns
an instance of the `Async\Coroutine` class as a result.

Let's look at two examples:

```php
$result = file_get_contents('https://php.net');
echo "next line".__LINE__."\n";
```

This code:
1. first returns the contents of the PHP website,
2. then executes the `echo` statement.

```php
$coroutine = spawn file_get_contents('https://php.net');
echo "next line".__LINE__."\n";
```

This code:
1. starts a coroutine with the `file_get_contents` function.
2. The next line is executed without waiting for the result of `file_get_contents`.
3. The coroutine is executed after the `echo` statement.

The `spawn` construct is available in two variations:
* `spawn function_call` - creates a coroutine from a callable expression
* `spawn closure_block` - creates a coroutine and defines a closure

```php
// Executing a known function
spawn [with <scope>] <function_call>;

// Closure form
spawn [with <scope>] [static] [use(<parameters>)][: <returnType>] {
    <codeBlock>
};
```

*where:*

`function_call` - a valid function call expression:

- call a standard PHP function:

```php
spawn file_get_contents('file1.txt');
```

- call a user-defined function:

```php
function example(string $name): void {
    echo "Hello, $name!";
}

spawn example('World');
```

- call a static method:

```php
spawn Mailer::send($message);
```

- call a method of an object:

```php
$object = new Mailer();
spawn $object->send($message);
```

- self, static or parent keyword:

```php
spawn self::send($message);
spawn static::send($message);
spawn parent::send($message);
```

- call `$class` method:

```php
$className = 'Mailer';
spawn $className::send($message);
```

- expression:

```php
// Use result of foo()
spawn (foo())();
// Use foo as a closure
spawn (foo(...))();
// Use ternary operator
spawn ($option ? foo() : bar())();
// Scary example
spawn (((foo())))();
```

- call array dereference:

```php
$array = [fn() => sleep(1)];
spawn $array[0]();
```

- new dereference:

```php
class Test {
    public function wait(): void {
        sleep(1);
    }
}

spawn new Test->wait();
```

- call dereferenceable scalar:

```php
spawn "sleep"(5);
```

- call short closure:

```php
spawn (fn() => sleep(1))();
```

#### Spawn closure expression

Allows creating a coroutine from a closure directly when using `spawn`:

```php
spawn [with <scope>] [static] [use(<parameters>)[: <returnType>]] {
    <codeBlock>
};
```

- full form:

```php
$file = 'main.log';

spawn use($file): string {
    $result = file_get_contents($file);
    
    if($result === false) {
        throw new Exception("Error reading $file");
    }
    
    return $result;
};
```

- short form:

```php
spawn {
    return file_get_contents('main.log');
};
```

- with return type:

```php
spawn use():string {
    return file_get_contents('main.log');
};
```

- with static keyword:

Define closure as static:

```php
class Test {
    private $property = 'main.log';
    public function method(): void {
        spawn static {
            //$this->property <- not available    
        };
    }
}
```

#### With scope expression

The `with` keyword allows specifying the scope in which the coroutine.

```php
$scope = new Async\Scope();

$coroutine = spawn with $scope use():string {
    return gethostbyname('php.net');
};

function defineTargetIpV4(string $host): string {
    return gethostbyname($host);
}

spawn with $scope test($host);
```

The `scope` expression can be:
- A variable:

```php
spawn with $scope function:void {
    echo gethostbyname('php.net').PHP_EOL;
};
```

- The result of a method or function call:

```php
spawn with $this->scope $this->method();
spawn with $this->getScope() $this->method();
```

### Suspension

A coroutine can suspend itself at any time using the `suspend` keyword:

```php
function example(string $name): void {
    echo "Hello, $name!";
    suspend;
    echo "Goodbye, $name!";
}

spawn example('World');
spawn example('Universe');
```

Expected output:

```
Hello, World!
Hello, Universe!
Goodbye, World!
Goodbye, Universe!
```

**Basic syntax:**

```php
    suspend;
```

**Wrong use:**

```php
    // not function
    suspend();
    // not part of the expression
    suspend + $any;
    // not parameter
    my_function(suspend);
```

The `suspend` keyword can be used only for the current coroutine.

The `suspend` keyword has no parameters and does not return any values, unlike the `yield` keyword.

The `suspend` keyword can be used in any function including from the **main execution flow**:

```php
function example(string $name): void {
    echo "Hello, $name!";
    suspend;
    echo "Goodbye, $name!";
}

$coroutine = spawn example('World');

// suspend the main flow
suspend;

echo "Back to the main flow";
```

Expected output:

```
Hello, World!
Back to the main flow
Goodbye, World!
```

The `suspend` keyword can be a throw point if someone resumes the coroutine externally with an exception.

```php
function example(string $name): void {
    echo "Hello, $name!";
    
    try {
        suspend;
    } catch (Exception $e) {
        echo "Caught exception: ", $e->getMessage();
    }
        
    echo "Goodbye, $name!";
}

$coroutine = spawn example('World');

// pass control to the coroutine
suspend;

$couroutine->cancel();
```

Expected output:

```
Hello, World!
Caught exception: cancelled at ...
Goodbye, World!
```

### Input/Output Operations And Implicit Suspension

I/O operations invoked within the coroutine's context transfer control implicitly:

```php
spawn function:void {
    echo "Start reading file1.txt\n";
    file_get_contents('file1.txt');
    echo "End reading file1.txt\n";
}
spawn function:void {
    echo "Start reading file2.txt\n";
    file_get_contents('file2.txt');
    echo "End readingfile2.txt\n";  
}

echo "Main flow";
```

Expected output:

```
Start reading file1.txt
Start reading file2.txt
Main flow
End reading file1.txt
End reading file2.txt
```

Inside each coroutine,
there is an illusion that all actions are executed sequentially,
while in reality, operations occur asynchronously.

This **RFC** proposes support for core PHP functions that require non-blocking input/output,
as well as support for CURL, Socket, and other extensions based on the **PHP Stream API**.
Please see Unaffected PHP Functionality.

### Awaitable interface

The `Awaitable` interface is a contract that allows objects to be used in the `await` expression.

The interface does not have any methods on the user-land side
and is intended for objects implemented as PHP extensions, such as:

- `Future`
- `Cancellation`

The following classes from this **RFC** also implement this interface:

- `Coroutine`
- `Scope`

### Await

The `await` keyword is used to wait for the completion of another coroutine
or any object that implements the `Awaitable` interface.:

```php
function readFile(string $fileName):string 
{
    $result = file_get_contents($fileName);
    
    if($result === false) {
        throw new Exception("Error reading file1.txt");
    }
    
    return $result;
}

$coroutine = spawn readFile('file1.txt');

echo await $coroutine;
// or
echo await spawn readFile('file2.txt');
```

`await` suspends the execution of the current coroutine until
the awaited one returns a final result or completes with an exception.

```php
function testException(): void {
    throw new Exception("Error");
}

try {
    await spawn testException();
} catch (Exception $e) {
    echo "Caught exception: ", $e->getMessage();
}
```

**Await basic syntax:**

```php
    [<resultExp> = ] await <awaitExp>;
```

**where:**

- `resultExp` - An expression that will receive the result of the awaited operation.
- `awaitExp` - An expression whose result must be an object with the `Async\Awaitable` interface.
- `cancellationExp` - An expression that limits the waiting time.
  Must be an object with the `Async\Awaitable` interface.

**Await expression:**

- A variable of the `Awaitable` interface

```php
    $readFileJob = spawn file_get_contents('file1.txt');
    
    $result = await $readFileJob;
```

- A function that returns an `Async\Awaitable` object:

```php
    function getContentsJobStarter(string $fileName): \Async\Coroutine {
        return spawn file_get_contents($fileName);
    }

    $result = await getContentsJobStarter('file1.txt');
```

- A new coroutine:

```php
    $result = await spawn file_get_contents('file1.txt');
```

- A new Awaitable object:

```php
    $result = await new Async\Future();
```

- A static method:

```php
    $result = await SomeClass::create();
```

- A method of an object:

```php
    $service = new Mailer();
    $result = await $service->sendMail("test@mail.com", "Hello!");
```

- A method of a class:

```php
    $serviceClass = 'Mailer';
    $result = await $serviceClass::sendAll();
```

- A valid expression:

```php
    $result = await ($bool ? foo() : bar());
```

#### Await with cancellation

##### Motivation

The wait operation is often combined with a `cancellation token`.  
In modern programming languages, the cancellation token is typically passed as
an additional parameter to functions,
which makes the semantics somewhat unclear.

For example:
```php
await all([...], $cancellation);
```

Clearer semantics would allow us to logically and visually separate the wait operation into two conditions:
1. What we're waiting for
2. How long we're willing to wait

For example:
```php
await all([...]) until $cancellation;
// or if timeout() returns a awaitable object
await all([...]) until timeout(5);
```

**basic syntax:**

```php
    [<resultExp> = ] await <awaitExp> [until <cancellationExp>];
```

**where:**

- A variable of the `Async\Awaitable` interface

```php
    $cancellation = Async\timeout(5000);
    $result = await $coroutine until $cancellation;
```

- A function that returns an `Awaitable` object

```php
    function getCancellation(): \Async\Awaitable {
        return spawn sleep(5);
    }

    $result = await $coroutine until getCancellation();
```

- A new coroutine

```php
    $result = await $coroutine until spawn sleep(5);
```

#### Using Coroutines with `until`

The `until` keyword allows using coroutines as a `CancellationToken`.
If an exception occurs in a coroutine that participates in `until`,
that exception will be thrown at the point where the `await` expression is called.

Example:

```php
function cancellationToken(): void {
    throw new Exception("Error");
}

try {
    await spawn sleep(5) until spawn cancellationToken();
} catch (Exception $exception) {
    echo "Caught exception: ", $exception->getMessage();
}
```

**Expected output:**

```
Caught exception: Error
```

> ⚠️ **Warning:** Note that completing the coroutine's await
> does not affect the lifetime of the coroutine used with `until`.

### Edge Behavior

The use of `spawn`/`await`/`suspend` is allowed in almost any part of a PHP program.
This is possible because the PHP script entry point forms the **main execution thread**,
which is also considered a coroutine.

As a result, keyword like `suspend` and `currentCoroutine()` will behave the same way as in other cases.

If only **one coroutine** exists in the system, calling `suspend` will immediately return control.

The `register_shutdown_function` handler operates in synchronous mode,
after asynchronous handlers have already been destroyed.
Therefore, the `register_shutdown_function` code should not use the concurrency API.
The `suspend` keyword will have no effect, and the `spawn` operation will not be executed at all.

### Coroutine Scope

> **Coroutine Scope** — the space associated with coroutines created using the `spawn` expression.

By default, all coroutines are associated with the **Global Coroutine Scope**:

```php
spawn file_get_contents('file1.txt'); // <- global scope

function readFile(string $file): void {
    return file_get_contents($file); // <- global scope
}

function mainTask(): void { // <- global scope
    spawn readFile('file1.txt'); // <- global scope
}

spawn mainTask(); // <- global scope
```

If an application never creates custom Scopes, its behavior is similar to coroutines in Go:
* Coroutines are not explicitly linked to each other.
* The lifetime of coroutines is not limited.

To manage the lifetime of coroutines and wait for their results, it's convenient to organize them into groups.
`Coroutine Scope` is a basic primitive
that helps associate coroutines with other PHP objects and track their execution at the group level.

`Coroutine Scope` is especially useful when you need to bind coroutines to a PHP object
and ensure their execution is terminated as soon as a destructor or a special method is called.

`Coroutine Scope` can be used to implement the **structural concurrency** pattern
(see [structured concurrency](#structured-concurrency)).

`Coroutine Scope` helps organize a hierarchy of coroutine groups
and implement all possible scenarios for waiting and management, taking the hierarchy into account.

Let’s look at an example:

```php

use Async\Scope;

$scope = new Scope();

spawn with $scope { // <- Task1 will be executed in the $scope context
    spawn { // <- Subtask executed in the ChildScope context  
        echo "Child1 of Task 1\n";
        
        spawn { // <- Subtask executed in the ChildScope context
            echo "Child2 of Task 1\n";
        };        
    };
    
    echo "Task 1\n";
};

spawn with $scope { // <- Task2 will be executed in the $scope context
    echo "Task 2\n";
    
    spawn { // <- Subtask executed in the ChildScope context
        echo "Child1 of Task 2\n"; 
    };
};

await $scope->allTasks();
```

**Expected output:**

```
Task 1
Task 2
Child1 of Task 1
Child1 of Task 2
Child2 of Task 1
```

Structure:

```
main()                          ← defines a $scope
├── task1()                     ← runs in the $scope
│   └── subtask1()              ← runs in the childScope
│   └── subtask2()              ← runs in the childScope
└── task2()                     ← runs in the $scope
    └── subtask3()              ← runs in the childScope
```

The expression `spawn in $scope` makes two important changes:
1. Creates a coroutine that is attached to `$scope`, which is **considered** the parent.
2. When another coroutine is created inside the new coroutine, it is linked to a child `Scope`,
   which is specifically created for all child coroutines.

```
$scope = new Scope();
├── task1()
├── task2()
│
├── $childScope
│   └── subtask1()
│   └── subtask2()
    └── subtask3()
```

This leads to two important consequences:
1. The direct descendants of `$scope` are only those tasks that were explicitly attached to the `Scope`.
2. All other tasks belong either to child `Scopes`, which were created explicitly or implicitly, or to other `Scopes`.

This approach ensures that no "accidental tasks" are added to the `$scope`.  
All other tasks created via `spawn` will be explicit child coroutines.

If the `$scope` object is destroyed (i.e., its destructor is called),
the coroutines that did not have time to complete will be marked as **zombie coroutine**.

**Zombie coroutines** are considered a result of a programming error and are handled specially  
(See section: [Zombie coroutine policy](#zombie-coroutine-policy)).

```php
use Async\Scope;

$scope = new Scope();

spawn with $scope {
    spawn {
        echo "Child of Task 1\n";
    };
    
    echo "Task 1\n";    
};

unset($scope);
```

**Expected output:**

```
Warning: Coroutine is zombie at ...
Task 1
Child of Task 1
```

This happens because the `Scope` object loses its last reference,
triggering the destructor, which monitors the execution progress of all child coroutines.

This makes `Scope` objects a good (though not the best) tool for managing the lifetime of coroutines.  
For example, you can assign the `$scope` object to another object and explicitly control its lifetime:

```php
use Async\Scope;

class Service 
{
    private Scope $scope;
    
    public function __construct() {
        $this->scope = new Scope();
    }
    
    public function __destruct() {
        $this->scope->disposeAfterTimeout(5000);
    }
    
    public function run(): void {
        spawn with $this->scope {
            echo "Task 1\n";
        };
    }    
}
```

#### Motivation

The Coroutine Scope in this **RFC**,
although inspired by the similarly named pattern from Kotlin,
is not an analog and has no equivalents in other languages.

The competitor to Coroutine Scope is the coroutine hierarchy,
where each coroutine started within another automatically becomes a child.

The main reason why a simple coroutine hierarchy was rejected is that the following code creates ambiguity:

```php
function subtask() {
    echo "Subtask\n";
}

function task() {
    spawn subtask();
}

spawn task();
task();
```

The main reason why a simple coroutine hierarchy was rejected is the absence of colored functions.  
The following code demonstrates how the hierarchy rule behaves ambiguously
when the same function can be called both as a coroutine and as a regular function.

In the example above, the `task()` function may or may not be a child coroutine.  
If a programmer writes `subtask` assuming it will be called using `spawn`,
they can easily break the behavior if the previous function
(which they may not even be aware of) is called without `spawn`.

Scope requires the programmer to create an explicit hierarchy.  
Its main purpose is to provide a space for resource control that affects all coroutines created within the Scope.

Resource control at the top level is a useful tool for organizing applications, frameworks, and libraries.

#### Point of Responsibility

The `spawn <callable>` expression allows you to create coroutines,
but it says nothing about who "owns" the coroutines.
This can become a source of errors, as resources are allocated without explicit management.

Scope helps solve this problem by implementing responsibility for coroutine ownership.

```php

function subtask() {
    
}

function task(): void
{
    spawn subtask();
}

$scope = new Scope();
spawn with $scope task();
```

```
main()                          ← defines a $scope and run task()
└── task()                      ← inherits $scope and run subtask()
    └── subtask()               ← inherits $scope
```

Once `$scope` is defined and a coroutine is created from it,
`$scope` is inherited throughout the entire depth of function calls.

This way, a place in the code is created that can control the lifetime of coroutines,
wait for their completion, handle exceptions, or cancel their execution.

Scope serves as a **point of responsibility** in managing coroutine resources.

This is especially useful for frameworks or top-level components that need to control resources and coroutines
created by lower-level functions without any knowledge of what those functions do.
Without Coroutine Scope, implementing such control at the application level is extremely difficult.

#### Scope waiting

`Scope` can be useful both for waiting on coroutines and for limiting their lifetime.  
It works especially well when the waiting logic is used together with a `try`–`finally` block:

```
main()                          ← defines a request Scope and run handleRequest()
└── handleRequest()
    └── processUser()
        └── fetchProfile()      ← creates coroutine
        └── fetchSettings()     ← creates coroutine
```

Code that creates a `Scope` typically intends to control only the tasks it has **explicitly** defined,  
because it knows nothing about the coroutines created deeper in the call stack.

Let's say we have a function that creates an array of tasks performing some background work:

```php
function fetchProfile(string $user): array
{
    spawn { // <- a zombie coroutine
        sleep(100);       
    };
}

function processUser(string $user): array
{
    $tasks = [];
    
    $tasks[] = spawn fetchProfile($user);
    $tasks[] = spawn fetchSettings($user);
    
    return await all($tasks);
}

function processAllUsers(string ...$users): array
{
    $coroutines = [];
    
    foreach ($users as $user) {
        $coroutines[] = spawn processUser($user);
    }
    
    return await all($coroutines);
}
```

The code `return await all($coroutines)` waits for the completion of all tasks
that were explicitly started within this function.
However, if the `processUser` function created other coroutines that,
for some reason, continue to run after `processUser` has finished,  
there is a risk of a resource leak.

This problem has three solutions:

1. You can wait for **all coroutines** that were created within a single `Scope`.  
   In this case, there is a higher chance that all nested calls will eventually complete properly.  
   However, there's also a higher risk that more tasks will remain in a waiting state,
   which means more memory consumption.

2. You can wait only for the coroutines that are explicitly needed,  
   and cancel all others that were implicitly created in the current `Scope` with an error.  
   In this case, there will be no resource leaks.  
   However, there's a risk that an important coroutine might be mistakenly cancelled.

3. You can create a **point of responsibility**,
   a special place in the code where resource leak control will be performed.

Scope helps implement any of these strategies.

```php
function processAllUsers(string ...$users): array
{
    $scope = new Scope();
    
    foreach ($users as $user) {
        spawn with $scope processUser($user);
    }
    
    try {
        return await $scope->directTasks();    
    } finally {
        $scope->dispose();
    }
}
```

In this example, the `processAllUsers` function must return the computation results of `processUser` for each user.  
`processAllUsers` has no knowledge of how `processUser` is implemented.

Using `await $scope->directTasks()`, `processAllUsers` waits for the results of all coroutines created
inside the `foreach ($users as $user)` loop.

The `directTasks` method returns an `Awaitable` object
that completes as soon as all direct child tasks of `$scope` have finished.  
Only tasks that were explicitly added via `spawn with` can be direct children of `$scope`.

When the `await $scope->directTasks()` has completed,  
the coroutine created by `processUser` will not stop its execution.

The `finally` block calls `$scope->dispose()`, which cancels all coroutines that were created within the `Scope`.
Calling `dispose()` explicitly cancels all zombie coroutines with a warning message.

You can also use the `disposeAfterTimeout` and `disposeSafely` methods
as alternative scenarios for cleaning up a `Scope`, see [Scope disposal](#scope-disposal).

**Another scenario:**

* There is a **Job Manager** that launches various tasks.
* It has no idea what exactly each task might do.
* However, it must guarantee that no more than **N** tasks are running at the same time.

To achieve this, it needs to wait until all tasks have completed — regardless of whether some coroutines
were mistakenly created or not — because for the Job Manager,
**waiting is more important than forcefully destroying data**.

```php
function processBackgroundJobs(string ...$jobs): array
{
    $scope = new Scope();
    
    foreach ($jobs as $job) {
        spawn with $scope processJob($users);
    }
    
    await $scope->allTasks();
}
```

The `allTasks()` method returns an `Awaitable` object that completes when all tasks of `$scope`,
including those within child scopes, have finished.

You can use an additional constraint like `until timeout(int $value)` to limit the waiting time,  
or you can approach the problem a bit differently:

```php
function processBackgroundJobs(string ...$jobs): array
{
    $scope = new Scope();
    
    foreach ($jobs as $job) {
        spawn with $scope processJob($users);
    }
    
    await $scope->directTasks();
    
    try {
        await $scope->allTasks() until timeout(1);
    } catch (CancellationException) {
       logWrongTasks($scope);
    }
    
    await $scope->allTasks() until timeout(10000);
    $scope->dispose();
}
```

This demonstrates a soft cancellation strategy.  
First, the code waits for the main tasks to complete.  
Then, it waits for any remaining tasks. If there are any, a `CancellationException` is thrown,  
and the code logs information about which coroutines were not properly completed.  
After that, it waits for their completion again.

#### directTasks and allTasks

The `Scope::directTasks` and `Scope::allTasks` methods return a notifier object,
which can be used in combination with `await`
to suspend the coroutine until the tasks within `$scope` have completed execution.

| Feature                          | `Scope::directTasks`                                  | `Scope::allTasks`                                       |
|----------------------------------|-------------------------------------------------------|---------------------------------------------------------|
| **Returns**                      | `Awaitable` (notifier object)                         | `Awaitable` (notifier object)                           |
| **What it waits for**            | Only direct child tasks of `$scope`                   | All tasks within `$scope`, including in child scopes    |
| **Includes nested scopes**       | No                                                    | Yes                                                     |
| **Used for**                     | Waiting for explicitly spawned tasks in current scope | Waiting for complete task tree under `$scope`           |
| **Common use case**              | Fine-grained control over immediate child tasks       | Ensuring total completion of all tasks in the hierarchy |


#### awaitAllIgnoringErrors

Sometimes it's necessary to wait for all tasks to complete before exiting a function permanently.

For example, in a web server scenario, when a user presses **CTRL-C**, 
the program should stop executing: 
* First, the `cancel()` method is called, which cancels all child tasks. But that's not the end yet. 
* The tasks are still running. Therefore, it's essential to explicitly wait for them to finish.

```php
    // Wait for Ctrl+C
    try {
       await Async\signal(SIGINT);
    } finally {
       $serverScope->cancel(new Async\CancellationException("Server shutting down"));
       
       echo "Shutting down server...\n";
       
       try {
           $serverScope->awaitAllIgnoringErrors(errorHandler: function (Async\Scope $scope, Async\Coroutine $coroutine, Throwable $e) {
                echo "Caught exception: {$e->getMessage()}\n in coroutine: {$coroutine->getSpawnLocation()}\n";
           }, cancellation: \Async\timeout(5000));
       } finally {
           // Cleanup code
       }
    }
```

The `Scope::awaitAllIgnoringErrors` method allows waiting for the complete termination of a `Scope`, 
ignoring exceptions. If an `$errorHandler` is defined, it can additionally output error information.

Please see also the [Scope::setExceptionHandler method](#error-handling).

#### Scope Hierarchy

A hierarchy can be a convenient way to describe an application as a set of dependent tasks:

* Parent tasks are connected to child tasks and are responsible for their execution time.
* Tasks on the same hierarchy level are independent of each other.
* Parent tasks should control their child's tasks.
* Child tasks MUST NOT control or wait for their parent tasks.
* It is correct if tasks at the same hierarchy level are only connected to tasks of the immediate child level.

```
WebServer  
├── Request Worker  
│   ├── request1 task
│   │   ├── request1 subtask A  
│   │   └── request1 subtask B  
│   └── request2 task  
│       ├── request2 subtask A  
│       └── request2 subtask B  
```

The work of a web server can be represented as a hierarchy of task groups that are interconnected.  
The `Request Worker` is a task responsible for handling incoming requests. There can be multiple requests.  
Each request may spawn subtasks. On the same level, all requests form a group of request-tasks.

`Scope` is fit for implementing this concept:

```
WebServer  
├── Request Worker  
│   ├── request1 Scope  
│   │   ├── request1 subtask A  
│   │   │   └── subtask A Scope  
│   │   │       ├── sub-subtask A1  
│   │   │       └── sub-subtask A2  
│   │   └── request1 subtask B  
│   └── request2 Scope  
│       ├── request2 subtask A  
│       └── request2 subtask B  
│           └── subtask B Scope  
│               └── sub-subtask B1  
```

A new child `Scope` can be created using a special constructor:  
`Scope::inherit()`.  
It returns a new `Scope` object that acts as a child.  
A coroutine created within the child `Scope` can also be considered a child relative to the coroutines in the parent `Scope`.

The advantage of a child `Scope` is that it’s not “detached” — it’s attached to its parent.
The code that owns the parent `Scope` can control the entire hierarchy of coroutines at once.

Let’s look at an example.

```php
use Async\Scope;
use Async\CancellationException;

function connectionChecker($socket, callable $cancelToken): void
{
    while (true) {
        if(feof($socket)) {
            $cancelToken("The connection was closed by user");
            return;
        }                               
        
        Async\delay(1000);
    }
}

function connectionLimiter(callable $cancelToken): void
{
   Async\delay(10000);
   $cancelToken("The request processing limit has been reached.");   
}

function connectionHandler($socket): void
{
    $scope = Scope::inherit();

    spawn with $scope use($socket, $scope) {
    
        $limiterScope = Scope::inherit();

        $cancelToken = fn(string $message) => $scope->cancel(new CancellationException($message));        

        // Limiter coroutine
        spawn with $limiterScope connectionLimiter($cancelToken);
        
        // A separate coroutine checks that the socket is still active.    
        spawn with $limiterScope connectionChecker($socket, $cancelToken);
    
        try {
            sendResponse($socket, dispatchRequest(parseRequest($socket)));
        } catch (\Exception $exception) {
            fwrite($socket, "HTTP/1.1 500 Internal Server Error\r\n\r\n");
        } finally {
            fclose($socket);
            $scope->cancel();
        }
    };
}

function socketServer(): void
{
    $scope = new Scope();

    // Child coroutine that listens for a shutdown signal
    spawn with $scope use($scope) {
        try {
            await Async\signal(SIGINT);
        } finally {
            $scope->cancel(new CancellationException('Server shutdown'));
        }        
    }

    try {
       // Main coroutine that listens for incoming connections
       await spawn with $scope {
           while ($socket = stream_socket_accept($serverSocket, 0)) {            
               connectionHandler($socket);
           }
       };    
    } catch (\Throwable $exception) {
        echo "Server error: ", $exception->getMessage(), "\n";
    } finally {
        echo "Server should be stopped...\n";
        
        // Graceful exit
        try {
            $scope->cancel();
            await $scope->allTasks() until Async\timeout(5);
            echo "Server stopped\n";
        } catch (\Throwable $exception) {
            // Force exit
            echo "Server error: ", $exception->getMessage(), "\n";
            throw $exception;
        }
    }
}
```
Let's examine how this example works.

1. `socketServer` creates a new Scope for coroutines that will handle all connections.
2. Each new connection is processed using `connectionHandler()` in a separate `Scope`,
   which is inherited from the main one.
3. `connectionHandler` creates a new `Scope` for the `connectionLimiter` and `connectionChecker` coroutines.
4. `connectionHandler` creates coroutine: `connectionLimiter()` to limit the processing time of the request.
5. `connectionHandler` creates coroutine, `connectionChecker()`, to monitor the connection's activity.
   As soon as the client disconnects, `connectionChecker` will cancel all coroutines related to the request.
6. If the main `Scope` is closed, all coroutines handling requests will also be canceled.

```
GLOBAL <- globalScope
│
├── socketListen (Scope) <- rootScope
│   │
│   ├── connectionHandler (Scope) <- request scope1
│   │   └── connectionLimiter (Coroutine) <- $limiterScope
│   │   └── connectionChecker (Coroutine) <- $limiterScope
│   │
│   ├── connectionHandler (Scope) <- request scope2
│   │   └── connectionLimiter (Coroutine) <- $limiterScope
│   │   └── connectionChecker (Coroutine) <- $limiterScope
│   │
```

The `connectionHandler` doesn't worry if the lifetimes of the `connectionLimiter` or `connectionChecker`
coroutines exceed the lifetime of the main coroutine handling the request,
because it is guaranteed to call `$scope->cancel()` when the main coroutine finishes.

`$limiterScope` is used to explicitly define a child-group of coroutines
that should be cancelled when the request is completed. This approach minimizes errors.

On the other hand, if the server receives a shutdown signal,
all child `Scopes` will be cancelled because the main `Scope` will be cancelled as well.

Note that the coroutine waiting on `await Async\signal(SIGINT)` will not remain hanging in memory
if the server shuts down in another way, because `$scope` will be explicitly closed in the `finally` block.

#### Scope cancellation

The `cancel` method cancels all child coroutines and all child `Scopes` of the current `Scope`.:

```php
use function Async\Scope\delay;

$scope = new Scope();

spawn with $scope {
    spawn {
        delay(1000);
        echo "Task 1\n";
    };
    
    spawn {
        delay(2000);
        echo "Task 2\n";
    };
};

$scope->cancel();
```

**Expected output:**

```
```

#### Scope Cancellation Order

If a `Scope` has child `Scopes`, the coroutines in the child `Scopes` will be canceled first,
followed by those in the parent — from the bottom up in the hierarchy.
This approach increases the likelihood that resources will be released correctly.
However, it does not guarantee this,
since the exact order of coroutines in the execution queue cannot be determined with 100% certainty.

#### Scope disposal

The `Async\Scope` class implements several methods for resource cleanup:

- `dispose` – cleans up resources and cancels coroutines, possibly with errors.
- `disposeSafely` – cleans up resources while preserving zombie coroutines.
- `disposeAfterTimeout` – cleans up resources and cancels coroutines after a timeout.

The `Scope::dispose*` methods terminates the execution of a `Scope` differently than `cancel()`.

It goes through all child coroutines that were explicitly defined using a `spawn with` expression and cancels them.
All implicit coroutines that have not completed execution are marked as **Zombie**.

When a **Zombie** coroutine is detected, PHP generates a warning indicating the location where the coroutine was started.
The later behavior depends on the selected strategy:

- `dispose` – immediately cancels the coroutine.
- `disposeAfterTimeout` – delays the cancellation for a specified duration.
- `disposeSafely` – runs the coroutine under the **Zombie** policy.

```php
use function Async\Scope\delay;

$scope = new Scope();

spawn in $scope {
    spawn {
        delay(1000);
        echo "Task 1\n";
    };
    
    spawn {
        delay(2000);
        echo "Task 2\n";
    };
    
    echo "Root task\n";
};

$scope->disposeSafely();
```

**Expected output:**

```
Warning: Coroutine is zombie at ...
Warning: Coroutine is zombie at ...
Warning: Coroutine is zombie at ...
Root task
Task 1
Task 2
```

Compare this to the `dispose` method:

```php

use function Async\Scope\delay;

$scope = new Scope();

spawn in $scope {
    spawn {
        delay(1000);
        echo "Task 1\n";
    };
    
    spawn {
        delay(2000);
        echo "Task 2\n";
    };
    
    echo "Root task\n";
};

$scope->dispose();
```

**Expected output:**

```
Warning: Coroutine is zombie at ...
Warning: Coroutine is zombie at ...
Warning: Coroutine is zombie at ...
```

#### Spawn with disposed scope

When the `cancel()` or `dispose()` method is called, the `Scope` is marked as closed.  
Attempting to launch a coroutine with this Scope will result in a fatal exception.

```php
$scope = new Scope();

spawn with $scope {
    echo "Task 1\n";
};

$scope->cancel();

spawn with $scope { // <- Fatal error
    echo "Task 2\n";
};
```

### Async blocks

The `async` block allows you to create a code section in which
the `$scope` is explicitly created and explicitly disposed.

The following is a code example:

```php
$scope = new Scope();

try {
   await spawn with $scope {
       echo "Task 1\n";
   };     
} finally {
   $scope->disposeSafely();
}
```

Can be written using an `async` block as follows:

```php
async $scope {
   await spawn {
       echo "Task 1\n";
   };
}
```

The `async` block does the following:

1. It creates a new `Scope` object and assigns it to the variable specified at the beginning of the block as `$scope`.
2. All coroutines will, by default, be created within `$scope`.
   That is, expressions like `spawn <callable>` will be equivalent to `spawn with $scope <callable>`.
3. When the async block finishes its execution,
   it calls the `Scope::disposeSafely` method,
   or `Scope::dispose` if the `bounded` attribute is specified.

#### Motivation

The `async` block allows for describing groups of coroutines in a clearer
and safer way than manually using `Async\Scope`.

* **Advantages**: Using `async` blocks improves code readability and makes it easier to analyze with static analyzers.
* **Drawback**: an `async` block is useless if `Scope` is used as an object property.

Consider the following code:

```php
function generateReport(): void
{
    $scope = Scope::inherit();

    try {
        [$employees, $salaries, $workHours] = await Async\all([
            spawn with $scope fetchEmployees(),
            spawn with $scope fetchSalaries(),
            spawn with $scope fetchWorkHours()
        ]);

        foreach ($employees as $id => $employee) {
            $salary = $salaries[$id] ?? 'N/A';
            $hours = $workHours[$id] ?? 'N/A';
            echo "{$employee['name']}: salary = $salary, hours = $hours\n";
        }

    } catch (Exception $e) {
        echo "Failed to generate report: ", $e->getMessage(), "\n";
    } finally {
        $scope->disposeSafely();        
    }
}
```

The `async` statement allows you to group coroutines together and explicitly limiting the lifetime of the `Scope`:

```php
function generateReport(): void
{
    try {
        async inherit $scope {
            [$employees, $salaries, $workHours] = await Async\all([
                spawn fetchEmployees(),
                spawn fetchSalaries(),
                spawn fetchWorkHours()
            ]);
    
            foreach ($employees as $id => $employee) {
                $salary = $salaries[$id] ?? 'N/A';
                $hours = $workHours[$id] ?? 'N/A';
                echo "{$employee['name']}: salary = $salary, hours = $hours\n";
            }        
        }
        
    } catch (Exception $e) {
        echo "Failed to generate report: ", $e->getMessage(), "\n";
    }
}

```

Using an `async` block with `bounded` attribute makes it easier to describe a pattern
where a group of coroutines is created with one main coroutine and several secondary ones.  
As soon as the main coroutine completes, all the secondary coroutines will be terminated along with it.

```php
function startServer(): void
{
    async bounded $serverSupervisor {
    
      // Secondary coroutine that listens for a shutdown signal
      spawn use($serverSupervisor) {
         await Async\signal(SIGINT);
         $serverSupervisor->cancel(new CancellationException("Server shutdown"));
      }    
    
      // Main coroutine that listens for incoming connections
      await spawn {
            while ($socket = stream_socket_accept($serverSocket, 0)) {            
                connectionHandler($socket);
            }
        };
    }
}
```

In this example, the server runs until `stream_socket_accept` returns `false`, or until the user presses **CTRL-C**.

#### async syntax

```php
async [inherit] [bounded] [<scope>] {
    <codeBlock>
}
```

**where:**

- `scope` - a variable that will hold the `Async\Scope` object.

options:

```php
// variable
async $scope {}
```

wrong use:

```php
// array element
async $scope[0] {}
async $$scope {}
// object property
async $object->scope {}
async Object::$scope {}
// function call
async getScope() {}
// The nullsafe operator is not allowed.  
async $object?->scope
// Using references is not allowed.
async &$object
```

- `inherit` - a keyword that allows inheriting the parent `Scope` object.

- `bounded` - a keyword that cancels all child coroutines
  if they have not been completed by the time the `Scope` block exits.
  Without this attribute, such coroutines are marked as **Zombie**.

- `codeBlock` - a block of code that will be executed in the `Scope` context.

### Structured concurrency support

**Structured concurrency** allows organizing coroutines into a group or hierarchy
to manage their lifetime or exception handling.

Structural concurrency implies that a parent cannot complete until all its child elements have finished executing.
This behavior helps prevent the release of resources allocated in the parent coroutine
until all children have completed their execution.

The following code implements this idea:

```php
use Async\Scope;

$source = fopen('input.txt', 'r');
$target = fopen('output.txt', 'w');

$buffer = null;

try {
    async $scope {

        // Read data from the source file
        spawn use(&$buffer, $source) {
            while (!feof($source)) {
                if ($buffer === null) {
                    $chunk = fread($source, 1024);
                    $buffer = $chunk !== false && $chunk !== '' ? $chunk : null;
                }

                suspend;
            }

            $buffer = '';
        };

        // Write data to the target file
        spawn use(&$buffer, $target) {
            while (true) {
                if (is_string($buffer)) {
                    if ($buffer === '') {
                        break; // End of a file
                    }

                    fwrite($target, $buffer);
                    $buffer = null;
                }

                suspend;
            }
            
            echo "Copy complete.\n";
        };

        await $scope->allTasks();
    };
} finally {
    fclose($source);
    fclose($target);
}
```

In this example, the main task opens files in order to process data in subtasks.  
The files must remain open until the subtasks are completed.  
This illustrates the key idea of structured concurrency:
tying the lifetime of child tasks to the scope that allocates resources.  
Both the child tasks and the resources must be cleaned up in a well-defined order.

### Error detection

Detecting erroneous situations when using coroutines is an important part of analyzing an application's reliability.

The following scenarios are considered potentially erroneous:

1. A coroutine belongs to a global scope and is not awaited by anyone (a **zombie coroutine**).
2. The root scope has been destroyed (its destructor was called), but no one awaited
   it or ensured that its resources were explicitly cleaned up (e.g., by calling `$scope->cancel()` or `$scope->dispose()`).
3. Tasks were not cancelled using the `cancel()` method, but through a call to `dispose()`.  
   This indicates that the programmer did not intend to cancel the execution of the coroutine,  
   yet it happened because the scope was destroyed.
4. Deadlocks caused by circular dependencies between coroutines.

**PHP** will respond to such situations by issuing **warnings**, including debug information about the involved coroutines.  
Developers are expected to write code in a way that avoids triggering these warnings.

#### Error mitigation strategies

The only way to create **zombie coroutines** is by using the `spawn` expression in the `globalScope`.  
However, if the initial code explicitly creates a scope and treats it as the application's entry point,
the initializing code gains full control — because `spawn <callable>` will no longer
be able to create a coroutine in `globalScope`, thus preventing the application from hanging beyond the entry point.

There’s still a way to use global variables and `new Scope` to launch a coroutine that runs unchecked:

```php
$GLOBALS['my'] = new Scope();
spawn with $GLOBALS['my'] { ... };
```

But such code can't be considered an accidental mistake.

To avoid accidentally hanging coroutines whose lifetimes were not correctly limited, follow these rules:

* Use **separate Scopes** for different coroutines. This is the best practice,
  as it allows explicitly defining lifetime dependencies between Scopes.
* Use `Scope::dispose()`. The `dispose()` method cancels coroutine execution and logs an error.
* Don’t mix semantically different coroutines within the same `Scope`.
* Avoid building hierarchies between `Scopes` with complex interdependencies.
* Do not use cyclic dependencies between `Scopes`.
* The principle of single point of responsibility and `Scope` ownership.
  Do not pass the `Scope` object to different coroutine functions (unless the action happens in a closure).
  Do not store `Scope` objects in different places.
  Violating this rule can lead to manipulations with `Scope`,
  which may cause a deadlock or disrupt the application's logic.
* Child coroutines should not wait for their parents.
  Child Scopes should not wait for their parents.

```php
namespace ProcessPool;

use Async\Scope;

final class ProcessPool
{
    private Scope $watcherScope;
    private Scope $poolScope;
    private Scope $jobsScope;
    /**
     * List of pipes for each process.
     * @var array
     */
    private array $pipes = [];
    /**
     * Map of process descriptors: pid => bool
     * If the value is true, the process is free.
     * @var array
     */
    private array $descriptors = [];
    
    public function __construct(readonly public string $entryPoint, readonly public int $max, readonly public int $min)
    {
        // Define the coroutine scopes for the pool, watcher, and jobs
        $this->poolScope = new Scope();
        $this->watcherScope = new Scope();
        $this->jobsScope = new Scope();
    }
    
    public function __destruct()
    {
        $this->watcherScope->dispose();
        $this->poolScope->dispose();
        $this->jobsScope->dispose();
    }
    
    public function start(): void
    {
        spawn with $this->watcherScope $this->processWatcher();
        
        for ($i = 0; $i < $this->min; $i++) {
            spawn with $this->poolScope $this->startProcess();
        }
    }
    
    public function stop(): void
    {
        $this->watcherScope->cancel();
        $this->poolScope->cancel();
        $this->jobsScope->cancel();
    }
    
    private function processWatcher(): void
    {
        while (true) {            
            try {
                await $this->poolScope->directTasks();
            } catch (StopProcessException $exception)  {
                echo "Process was stopped with message: {$exception->getMessage()}\n";
                
                if($exception->getCode() !== 0 || count($this->descriptors) < $this->min) {
                    spawn with $this->poolScope $this->startProcess();
                }
            }
        }
    }
}
```

The example above demonstrates how splitting coroutines into
Scopes helps manage their interaction and reduces the likelihood of errors.

Here, `watcherScope` monitors tasks in `poolScope`.
When a process finishes, the watcher detects this event and, if necessary, starts a new process or not.
The monitoring logic is completely separated from the process startup logic.

The lifetime of `watcherScope` matches that of `poolScope`, but not longer than the lifetime of the watcher itself.

The overall lifetime of all coroutines in the `ProcessPool` is determined by the lifetime of the `ProcessPool`
object or by the moment the `stop()` method is explicitly called.

#### Zombie coroutine policy

Coroutines whose lifetime extends beyond the boundaries of their parent `Scope`
are handled according to a separate **policy**.

This policy aims to strike a balance between uncontrolled resource leaks and the need to abruptly
terminate coroutines, which could lead to data integrity violations.

If there are no active coroutines left in the execution queue and no events to wait for, the application is considered complete.

Zombie coroutines differ from regular ones in that they are not counted as active.
Once the application is considered finished,
zombie coroutines are given a time limit within which they must complete execution.
If this limit is exceeded, all zombie coroutines are canceled.

The delay time for handling zombie coroutines can be configured using
a constant in the `ini` file: `async.zombie_coroutine_timeout`, which is set to two seconds by default.

If a coroutine is created within a user-defined `Scope`, the programmer
can set a custom timeout for that specific `Scope` using the `Scope::disposeAfterTimeout(int $ms)` method.

### Context

#### Motivation

Libraries and frameworks often use variables that are shared within a request to store common data.
These variables are not **Global** in the general sense,
but they essentially reflect a shared state related to the request or execution scope.

For example, the `TokenStorage` class
(https://github.com/symfony/symfony/blob/7.3/src/Symfony/Component/Security/Core/Authentication/Token/Storage/TokenStorage.php)
from `Symfony` allows retrieving the user token multiple times, as it is stored in a variable.

Or `/src/Illuminate/Auth/TokenGuard.php` from `Laravel`:

```php
    /**
     * Get the currently authenticated user.
     */
    public function user()
    {
        // If we've already retrieved the user for the current request we can just
        // return it back immediately. We do not want to fetch the user data on
        // every call to this method because that would be tremendously slow.
        if (! is_null($this->user)) {
            return $this->user; // <-- Shared state
        }

        $user = null;

        $token = $this->getTokenForRequest();
        
        // some code skipped

        return $this->user = $user;
    }
```

This code assumes that a single `process`/`thread` always handles only one request at a time.
However, in a concurrent web server environment,
shared states can no longer be used because the execution context may switch unexpectedly.

You can use `Coroutine ID` and `Map` to associate a unique coroutine ID with specific data.
However, in this case, you must ensure that the data is properly released
when the coroutine ceases to exist.

In addition to storing request-specific data,
concurrent code must also ensure the proper handling of input/output descriptors.
For example, when implementing a protocol, data must be sent in a specific sequence.
If a socket is used by two coroutines simultaneously for reading/writing,
the order of operations may be disrupted.

Another example is database transactions.
Code that starts a transaction cannot release the database connection socket until
the transaction is completed.

The `Async\Context` class is designed to help solve these issues.

#### Context API

The `Async\Context` class defines three groups of methods:
* Methods for retrieving values from the Map, considering parent contexts
* Methods for retrieving values only from the current context
* Methods for modifying or removing keys in the current context

| Method                                                                | Description                                             |
|-----------------------------------------------------------------------|---------------------------------------------------------|
| `find(string\|object $key): mixed`                                    | Find a value by key in the current or parent Context.   |
| `get(string\|object $key): mixed`                                     | Get a value by key in the current Context.              |
| `has(string\|object $key): bool`                                      | Check if a key exists in the current Context.           |
| `findLocal(string\|object $key): mixed`                               | Find a value by key only in the local Context.          |
| `getLocal(string\|object $key): mixed`                                | Get a value by key only in the local Context.           |
| `hasLocal(string\|object $key): bool`                                 | Check if a key exists in the local Context.             |
| `set(string\|object $key, mixed $value, bool $replace = false): self` | Set a value by key in the Context.                      |
| `unset(string\|object $key): self`                                    | Delete a value by key from the Context.                 |


**Context Slots** are an efficient mechanism for managing memory
associated with `Scope` or coroutine lifetimes.  
Once all coroutines owning the Scope complete,
or the Scope itself is terminated, all data in the slots will be released.

This helps the programmer associate data with coroutines without writing explicit cleanup code.

To ensure data encapsulation between different components,
**Coroutine Scope Slots** provide the ability to associate data using **key objects**.  
An object instance is unique across the entire application,
so code that does not have access to the object cannot read the data associated with it.

This pattern is used in many programming languages and is represented in JavaScript by a special class, **Symbol**.

```php
$key = 'pdo connection';

if(currentContext()->has($key)) {
    $pdo = currentContext()->get($key);
} else {
    $pdo = new PDO('sqlite::memory:');
    currentContext()->set($key, new PDO('sqlite::memory:'));
}
```

**Coroutine Scope Slots** can automatically dereference **WeakReference**.  
If you assign a **WeakReference** to a slot and then call `find()`,
you will receive the original object or `NULL`.

```php
function task(): void 
{
    // Should return the original object
    $pdo = currentContext()->find('pdo');
}

$pdo = new PDO('sqlite::memory:');
currentContext()->set('pdo', new WeakReference($pdo));

spawn task();
```

#### Context inheritance

The context belongs to the `Scope` and is created along with it.  
If a `Scope` is inherited from a parent, the new context also inherits the parent.  
Thus, the hierarchy of Scope objects forms exactly the same hierarchy of contexts.

```php

use Async\Scope;
use function \Async\currentContext;
use function \Async\rootContext;

function handleRequest($socket): void
{
    echo currentContext()->get('request_id')."\n"; // <-- From request context
    echo currentContext()->get('server_id')."\n"; // <-- From server context
    echo rootContext()->get('request_id')."\n"; // <-- Should be NULL
}

function startRequestHandler($socket): void
{
    $requestScope = Scope::inherit(); // <-- Inherit server context
    $requestScope->context->set('request_id', uniqid()); // <-- Override server context slot
    
    // Handle request in separate coroutine and scope
    spawn with $requestScope handleRequest($socket);
}

function startServer(): void
{
    $serverScope = new Scope();
    $serverScope->context->set('server_id', uniqid());
    $serverScope->context->set('request_id', null);
    
    while (true) {
        $socket = stream_socket_accept($serverSocket, 0);
        startRequestHandler($socket);
    }    
}
```

The special functions `Async\currentContext()` and `Async\rootContext()` help
quickly access the current context from any function.

`Async\rootContext()` returns the context at the very root of the hierarchy,
if it exists, or the global application context if it does not.

#### Coroutine local context

While a `Scope` can serve as a shared context in the coroutine hierarchy,
a coroutine's **local context** is a personal data store strictly tied to the coroutine's lifetime.
The local context allows associating data slots that are automatically freed once the coroutine completes.

The local coroutine context is accessible via the `Async\coroutineContext()` function,
which returns an `Async\Context` object.
The `Async\Context` class provides the same methods for working with slots as the `Scope` class:

```php
function task(): void 
{
    coroutineContext()->set('data', 'This local data');
    
    spawn function() {
         // No data will be found
         echo coroutineContext()->find('data')."\n";
    };
}
```

Using a coroutine's local context can be useful for associating objects
with a coroutine that **MUST** be unique to each coroutine.

For example, a database connection:

```php
<?php

namespace Async;

use PDO;
use RuntimeException;

class ConnectionProxy
{
    private PDO $connection;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }
    
    public function __destruct()
    {
        ConnectionPool::default()->releaseConnection($this->connection);
    }
}

final class ConnectionPool
{
    static private $pool = null;

    public static function default(): ConnectionPool
    {
        if (self::$pool === null) {
            self::$pool = new ConnectionPool();
        }
        
        return self::$pool;
   }

    private array $pool = [];
    private int $maxConnections = 10;

    public function getConnection(): ConnectionProxy
    {
        if (!empty($this->pool)) {
            return new ConnectionProxy(array_pop($this->pool));
        }

        if (count($this->pool) < $this->maxConnections) {
            return new ConnectionProxy(PDO("mysql:host=localhost;dbname=test", "user", "password"));
        }

        throw new RuntimeException("No available database connections.");
    }

    public function releaseConnection(PDO $connection): void
    {
        $this->pool[] = $connection;
    }
}

function getDb(): ConnectionProxy
{
    static $key = new Key('db_connection');
    
    $context = Async\coroutineContext();

    if ($context->has($key)) {
        return $context->get($key);
    }

    $connection = ConnectionPool::default()->getConnection();

    $context->set($key, $connection);

    return $connection;
}

function printUser(int $id): void 
{
    $db = getDb();
    $stmt = $db->query("SELECT * FROM users WHERE id = $id");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($users);
}

spawn printUser(1);
spawn printUser(2);
```

This code relies on the fact that an instance of the `ConnectionProxy`
class will be destroyed as soon as the coroutine completes.  
The destructor will be called, and the connection will automatically return to the pool.

### Error Handling

An uncaught exception in a coroutine follows this flow:

1. If the coroutine is awaited using the `await` keyword,
   the exception is propagated to the awaiting points.
   If multiple points are awaiting, each will receive the same exception
   (**Each await point will receive the exact same exception object, not cloned**).
2. The exception is passed to the `Scope`.
3. If the `Scope` has an exception handler defined, it will be invoked.
4. If the `Scope` does not have an exception handler, the `cancel()` method is called,
   canceling all coroutines in this scope from top to bottom in the hierarchy, including all child scopes.
5. If the `Scope` has responsibility points, i.e., the construction `await $scope`,
   all responsibility points receive the exception.
6. Otherwise, the exception is passed to the parent scope if it is defined.
7. If there is no parent scope, the exception falls into `globalScope`,
   where the same rules apply as for a regular scope.

```puml
@startuml
start
:Unhandled Exception Occurred;
if (Await keyword is used?) then (Yes)
    :Exception is propagated to await points;
else (No)
    while (Scope exists?)
        :Exception is passed to Scope;
        if (Scope has an exception handler?) then (Yes)
            :Invoke exception handler;
            stop
        else (No)
            :Scope::cancel();
        endif
        if (Scope has responsibility points?) then (Yes)
            :All responsibility points receive the exception;
            stop
        endif
        if (Parent Scope exists?) then (Yes)
            :Pass exception to Parent Scope;
        else (No)
            :Pass exception to globalScope;
            break
        endif
    endwhile
endif
stop
@enduml
```

**Example:**

```php
use Async\Scope;

$scope = new Scope();

spawn with $scope {
    throw new Exception("Task 1");
};

$exception1 = null;
$exception2 = null;

$scope2 = new Scope();

spawn with $scope2 use($scope, &$exception1) {
    try {
        await $scope;
    } catch (Exception $e) {
        $exception1 = $e;
        echo "Caught exception1: {$e->getMessage()}\n";
    }
};

spawn with $scope2 use($scope, &$exception2) {
    try {
        await $scope;
    } catch (Exception $e) {
        $exception2 = $e;
        echo "Caught exception2: {$e->getMessage()}\n";
    }
};

await $scope2->directTasks();

echo $exception1 === $exception2 ? "The same exception\n" : "Different exceptions\n";
```

If an exception reaches `globalScope` and is not handled in any way,
it triggers **Graceful Shutdown Mode**, which will terminate the entire application.

The `Scope` class allows defining an exception handler that can prevent exception propagation.

For this purpose, two methods are used:
- **`setExceptionHandler`** – triggers for any exceptions thrown within this **Scope**.
- **`setChildScopeExceptionHandler`** – triggers for exceptions from **child Scopes**.

**Example:**

```php
$scope = new Scope();
$scope->setExceptionHandler(function (Async\Scope $scope, Async\Coroutine $coroutine, Throwable $e) {
    echo "Caught exception: {$e->getMessage()}\n in coroutine: {$coroutine->getSpawnLocation()}\n";
});

$scope->spawn(function() {
    throw new Exception("Task 1");
});

Async\await($scope);
```

Using these handlers,
you can implement the **Supervisor** pattern, i.e.,
a **Scope** that will not be canceled when an exception occurs in coroutines.

The **`setChildScopeExceptionHandler`** method allows handling exceptions only from **child Scopes**,
which can be useful for implementing an algorithm where the **main Scope** runs core tasks,
while **child Scopes** handle additional ones.

For example:

```php

use Async\Scope;
use Async\Coroutine;

final class Service
{
    private Scope $scope;
    
    public function __construct()
    {
        $this->scope = new Scope();
        
        $this->scope->setChildScopeExceptionHandler(
        static function (Scope $scope, Coroutine $coroutine, \Throwable $exception): void {
            echo "Occurred an exception: {$exception->getMessage()} in Coroutine {$coroutine->getSpawnLocation()}\n";
        });
    }
    
    public function start(): void
    {
        spawn with $this->scope $this->run();
    }
    
    public function stop(): void 
    {
        $this->scope->cancel();
    }
    
    private function run(): void
    {
        while (($socket = $this->service->receive()) !== null) {
            spawn with Scope::inherit($this->scope) $this->handleRequest($socket);
        }
    }
}
```

`$this->scope` listens for new connections on the server socket.  
Canceling `$this->scope` means shutting down the entire service.

Each new connection is handled in a separate **Scope**, which is inherited from `$this->scope`.  
If an exception occurs in a coroutine created within a **child Scope**,
it will be passed to the `setChildScopeExceptionHandler` handler and will not affect
the operation of the service as a whole.

```puml
@startuml
actor Client
participant "Service (Main Scope)" as Service
participant "Request Handler (Child Scope)" as Handler

Client -> Service : New connection request
Service -> Handler : Create child scope and spawn coroutine

loop For each request
    Client -> Handler : Send request
    Handler -> Client : Send response
end

alt Exception in child scope
    Handler -> Service : Exception propagated to setChildScopeExceptionHandler
    note right: Exception is logged, service continues running
end

alt Main scope cancelled
    Service -> Handler : Cancel all child scopes
    Handler -> Client : Disconnect
end

@enduml
```

#### Responsibility points

A **responsibility point** is code that explicitly waits for the completion of a coroutine or a `Scope`:

```php
$scope = new Scope();

$scope->spawn(function() {
  throw new Exception("Task 1");        
});

try {
    await $scope;
} catch (\Throwable $e) {
     echo "Caught exception: {$e->getMessage()}\n";
}      
```

A **responsibility point** has a chance to receive
not only the result of the coroutine execution but also an unhandled exception.

#### Exception Handling

The `Scope` class provides a method for handling exceptions:

```php
$scope = new Scope();

spawn with $scope {
    throw new Exception("Task 1");
};

$scope->setExceptionHandler(function (Exception $e) {
    echo "Caught exception: {$e->getMessage()}\n";
});

await $scope;
```

An exception handler has the right to suppress the exception.  
However, if the exception handler throws another exception,
the exception propagation algorithm will continue.

#### onCompletion

The `onCompletion` method allows defining a callback function that will be invoked when a coroutine or scope completes.  
This method can be considered a direct analog of `defer` in Go.

```php
$scope = new Scope();

spawn with $scope {
    throw new Exception("Task 1");
};

$scope->onCompletion(function () {
    echo "Task 1 completed\n";
});

await $scope;
```

Or for coroutines:

```php
function task(): void 
{
    throw new Exception("Task 1");
}

$coroutine = spawn task();

$coroutine->onCompletion(function () {
    echo "Task completed\n";
});

```

The `onCompletion` semantics are most commonly used to release resources,
serving as a shorter alternative to `try-finally` blocks:

```php
function task(): void 
{
    $file = fopen('file.txt', 'r');    
    onCompletion(fn() => fclose($file));
    
    throw new Exception("Task 1");
}

spawn task();
```

### Cancellation

The cancellation operation is available for coroutines and scopes
using the `cancel()` method:

```php
function task(): void {}

$coroutine = spawn task();

// cancel the coroutine
$coroutine->cancel(new Async\CancellationException('Task was cancelled'));
```

The cancellation operation is implemented as follows:

1. If a coroutine has not started, it will never start.
2. If a coroutine is suspended, its execution will resume with an exception.
3. If a coroutine has already completed, nothing happens.

The `CancellationException`, if unhandled within a coroutine, is automatically suppressed after the coroutine completes.

> ⚠️ **Warning:** You should not attempt to suppress `CancellationException` exception,
> as it may cause application malfunctions.

```php
$scope = new Scope();

spawn with $scope {
    sleep(1);        
    echo "Task 1\n";
};

$scope->cancel(new Async\CancellationException('Task was cancelled'));
```

Canceling a `Scope` triggers the cancellation of all coroutines
within that `Scope` and all child `Scopes` in hierarchical order.

>
> **Note:** `CancellationException` can be extended by the user
> to add metadata that can be used for debugging purposes.
>

#### CancellationException handling

In the context of coroutines, it is not recommended to use `catch \Throwable` or `catch CancellationException`.

Since `CancellationException` does not extend the `\Exception` class,
using `catch \Exception` is a safe way to handle exceptions,
and the `finally` block is the recommended way to execute finalizing code.

```php
try {
    $coroutine = spawn {
        sleep(1);
        throw new \Exception("Task 1");
    };    
    
    spawn use($coroutine) {        
        $coroutine->cancel();
    };
    
    try {
        await $coroutine;        
    } catch (\Exception $exception) {
        // recommended way to handle exceptions
        echo "Caught exception: {$exception->getMessage()}\n";
    }
} finally {
    echo "The end\n";
}
```

Expected output:

```
The end
```

```php
try {
    $coroutine = spawn {
        sleep(1);
        throw new \Exception("Task 1");
    };    
    
    spawn use($coroutine) {        
        $coroutine->cancel();
    };
    
    try {
        await $coroutine;        
    } catch (Async\CancellationException $exception) {
        // not recommended way to handle exceptions
        echo "Caught CancellationException\n";
        throw $exception;
    }
} finally {
    echo "The end\n";
}
```

Expected output:

```
Caught CancellationException
The end
```

#### CancellationException propagation

The `CancellationException` affects PHP standard library functions differently.
If it is thrown inside one of these functions that previously did not throw exceptions,
the PHP function will terminate with an error.

In other words, the `cancel()` mechanism does not alter the existing function contract.
PHP standard library functions behave as if the operation had failed.

Additionally, the `CancellationException` will not appear in `get_last_error()`,
but it may trigger an `E_WARNING` to maintain compatibility with expected behavior
for functions like `fwrite` (if such behavior is specified in the documentation).

#### withoutCancellation function

Sometimes it's necessary to execute a critical section of code that must not be cancelled via `CancellationException`.
For example, this could be a sequence of write operations or a transaction.

For this purpose, the `Async\withoutCancellation` function is used,
which allows executing a closure in a non-cancellable (silent) mode.

```php
function task(): void 
{
    Async\withoutCancellation(fn() => fwrite($file, "Critical data\n"));
}

spawn task();
```

If a `CancellationException` was sent to a coroutine during withoutCancellation,
the exception will be thrown immediately after the execution of withoutCancellation completes.

#### exit and die keywords

The `exit`/`die` keywords called within a coroutine result in the immediate termination of the application.  
Unlike the `cancel()` operation, they do not allow for proper resource cleanup.

### Graceful Shutdown

When an **unhandled exception** occurs in a **Coroutine**
the **Graceful Shutdown** mode is initiated.
Its goal is to safely terminate the application.

**Graceful Shutdown** cancels all coroutines in `globalScope`,
then continues execution without restrictions, allowing the application to shut down naturally.  
**Graceful Shutdown** does not prevent the creation of new coroutines or close connection descriptors.
However, if another unhandled exception is thrown during the **Graceful Shutdown** process,
the second phase is triggered.

**Second Phase of Graceful Shutdown**
- All **Event Loop descriptors** are closed.
- All **timers** are destroyed.
- Any remaining coroutines that were not yet canceled will be **forcibly canceled**.

The further shutdown logic may depend on the specific implementation of the **Scheduler** component,
which can be an external system and is beyond the scope of this **RFC**.

The **Graceful Shutdown** mode can also be triggered using the function:

```php
Async\gracefulShutdown(\Throwable|null $throwable = null): void {}
```

from anywhere in the application.

### Deadlocks

A situation may arise where there are no active **Coroutines** in the execution queue
and no active handlers in the event loop.
This condition is called a **Deadlock**, and it represents a serious logical error.

When a **Deadlock** is detected, the application enters **Graceful Shutdown** mode
and generates warnings containing information about which **Coroutines** are in a waiting state
and the exact lines of code where they were suspended.

### Tools

The `Coroutine` class implements methods for inspecting the state of a coroutine.

| Method                                 | Description                                                                                                                                                                                            |
|----------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **`getSpawnFileAndLine():array`**      | Returns an array of two elements: the file name and the line number where the coroutine was spawned.                                                                                                   |
| **`getSpawnLocation():string`**        | Returns a string representation of the location where the coroutine was spawned, typically in the format `"file:line"`.                                                                                |
| **`getSuspendFileAndLine():array`**    | Returns an array of two elements: the file name and the line number where the coroutine was last suspended. If the coroutine has not been suspended, it may return `['',0]`.                           |
| **`getSuspendLocation():string`**      | Returns a string representation of the location where the coroutine was last suspended, typically in the format `"file:line"`. If the coroutine has not been suspended, it may return an empty string. |
| **`isSuspended():bool`**               | Returns `true` if the coroutine has been suspended                                                                                                                                                     |
| **`isCancelled():bool`**               | Returns `true` if the coroutine has been cancelled, otherwise `false`.                                                                                                                                 |
| **`getTrace():array`**                 | Returns the stack trace of the coroutine.                                                                                                                                                              |

The `Coroutine::getAwaitingInfo()` method returns an array with debugging information
about what the coroutine is waiting for, if it is in a waiting state.

The format of this array depends on the implementation of the **Scheduler** and the **Reactor**.

The `Async\getCoroutines()` method returns an array of all coroutines in the application.

### Prototypes

* [Async functions](./examples/Async/Async.php)
* [Coroutine](./examples/Async/Coroutine.php)
* [Coroutine Context](./examples/Async/Context.php)
* [Coroutine Scope](./examples/Async/Scope.php)

## Backward Incompatible Changes

Simultaneous use of the **True Async API** and the **Fiber API** is not possible.

- If `new Fiber()` is called first, the `Async\spawn` function will fail with an error.
- If `Async\spawn` is called first, any attempt to create a **Fiber** will result in an error.

## Proposed PHP Version(s)

PHP 8.6/ PHP 9.0