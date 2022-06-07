# `Moebius\Loop::queueMicrotask(Closure $callback, mixed $argument=null)`

[^1] The `queueMicrotask()` method queues a microtask queues a microtask to be executed at a safe time
prior to control returning to the main event loop.

The microtask is a short function which will run after the current task has completed its work and 
when there is no other code waiting to be run before control of the execution context is returned 
to the browser's event loop.

This lets your code run without interfering with any other, potentially higher priority, code that
is pending, but before the browser regains control over the execution context, potentially depending 
on work you need to complete. You can learn more about how to use microtasks and why you might choose 
to do so in [Mozillas microtask guide](https://developer.mozilla.org/en-US/docs/Web/API/HTML_DOM_API/Microtask_guide).

The importance of microtasks comes in its ability to perform tasks asynchronously but in a specific 
order. See [Using microtasks in JavaScript with queueMicrotask()](https://developer.mozilla.org/en-US/docs/Web/API/HTML_DOM_API/Microtask_guide)
for more details.

[^1] [Adapted from Mozillas documentation on queueMicrotask()](https://developer.mozilla.org/en-US/docs/Web/API/queueMicrotask)
