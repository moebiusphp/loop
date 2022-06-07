# `Moebius\Loop::writable(resource $fp, $callback=null): Handle`

The `writable()` method will request that the operating system notifies us as soon
as writing to the stream resource will not block.

It takes no resources to wait for a stream to become writable, and by scheduling
your callback this way you can allow enqueued tasks in the application to perform
work.

## Caution

Even if the operating system tells us that writing to the stream will no block the
process, there are circumstances where writing does block. You should therefore
always mark any streams as non-blocking via the `stream_set_blocking($fp, false)`
function call.

A read operation which blocks the process is a waste of resources. When a read
operation becomes blocking, the kernel will perform at least two context switches
before your process receives control again. This context switch is costly, and
should happen as few times as possible per second.

