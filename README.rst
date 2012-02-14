.. _kpk/Package: 

KPK PHP Libraries
=================

:Authors: Kitson Kelly
:Project Owner: Kitson Kelly

.. contents ::
   :depth: 2

This is a set of useful PHP libraries I have made over the years which I find handy.  They are distributed under
the`Modified BSD License <http://www.opensource.org/licenses/BSD-3-Clause>`_.

kpk/core/db
-----------

This library provides further abstraction from a PDO data source.  Currently it only supports PDO/SQLite, but could
easily be modified to support additional PDO data sources.

kpk/core/logging
----------------

This library is a logging library I use in my PHP applications.  It provides console and file logging capabilities, with
auto segmentation and log file management.  It also supports the concept of event level filtering.

kpk/core/RESTful
----------------

This is my abstract class that allows the creation of RESTful services.  In particular it is designed to interface with
consumers that are built using the Dojo Toolkit
`dojox/data/JsonRestStore <http://dojotoolkit.org/reference-guide/dojox/data/JsonRestStore.html>`_ or the
`dojo/store/JsonRest <http://dojotoolkit.org/reference-guide/dojo/store/JsonRest.html>`_ and supports the ability to do
advanced features like ranges and filtering.  It is designed so that you can easily combine it with the kpk/core/db
library to create a SQLite based RESTful service.