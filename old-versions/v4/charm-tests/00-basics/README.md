# Testing simple loop functions

These tests will test the simplest features of the event loop in a pure
way (without combining with other event loop features).

For each feature, there are three test variants:

 1. Test the feature without manually running the loop.
 2. Test the feature followed by a call to Loop::run().
 3. Test the feature followed by a call to Loop::run(function() { return false; });

