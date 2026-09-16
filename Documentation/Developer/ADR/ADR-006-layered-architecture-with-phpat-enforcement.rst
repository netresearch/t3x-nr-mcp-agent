..  include:: /Includes.rst.txt

.. _adr-006:

====================================================
ADR-006: Layered Architecture with PHPAt Enforcement
====================================================

**Status:** Accepted

**Date:** 2026-03-14

Context
=======

As the codebase grows, accidental dependency inversions (e.g. a Domain class
importing a Controller) are easy to introduce and hard to spot in code review.
PHP has no built-in module visibility; any class can import any other.

Decision
========

Define explicit dependency rules between architectural layers and enforce them
automatically via `PHPAt <https://github.com/carlosas/phpat>`_ architecture
tests, which run as part of the PHPStan pass in CI:

.. list-table::
   :header-rows: 1
   :widths: 20 40 40

   * - Layer
     - May depend on
     - Must NOT depend on
   * - ``Domain``
     - —
     - ``Controller``, ``Command``
   * - ``Service``
     - ``Domain``
     - ``Controller``, ``ConnectionPool``
   * - ``Controller``
     - ``Domain``, ``Service``
     - ``Command``
   * - ``Document``
     - ``Domain``
     - ``ChatService``, ``Controller``
   * - ``Command``
     - ``Domain``, ``Service``
     - ``Controller``

Architecture tests live in ``Tests/Architecture/LayerDependencyTest.php`` and
``Tests/Architecture/DocumentExtractorArchitectureTest.php``.

..  note::

    The ``Mcp`` layer this table once listed was removed with the extension's
    own MCP client in 0.12.0 (see :ref:`adr-003`). Its rules were left behind
    selecting an empty namespace, where they passed without ever checking
    anything; they have since been replaced by rules that bind to namespaces
    that exist (see the changelog).

Consequences
============

-   Violations are caught at CI time, not during code review.
-   The architecture self-documents: the test file is the authoritative
    dependency map.
-   PHPAt runs within the existing PHPStan pipeline — no additional CI step.
-   Adding new layers or relaxing rules requires a deliberate test change,
    making architectural drift visible in pull requests.
