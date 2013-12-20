cloudmarketwatch.com
====================

Front- and back-end code for cloudmarketwatch.com

What is this?
-------------

It's a project to track cloud prices across different providers.  The goals are to offer historical data, allow for comparisons between providers, and allow for someone to figure out the most economical way to purchase cloud resources for their needs.

Why?
----

My last few jobs required me to work quite a bit with AWS and their Hadoop offering.  Most of the time, it was cheaper to use spot nodes.  Sometimes, the market became terribly volatile, forcing us to manually switch strategies.  Most of the time, it was as simple as switching a configuration.  I want to be able to take that one step further.  I want a machine to be able to decide the best way to schedule work.

Figuring out the costs of cloud computing is difficult.  Writing an algorithm to decide such a thing requires a lot more than time and skill.  It also requires a continous stream of data and a means of comparison.  I'm hoping to be that data and means of comparison for people.

How?
----

My weapon of choice in doing this is Symfony2.