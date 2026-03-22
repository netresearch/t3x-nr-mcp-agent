..  include:: /Includes.rst.txt

=======
Testing
=======

Test infrastructure overview
=============================

The extension uses a layered test approach:

.. list-table::
   :header-rows: 1
   :widths: 25 35 40

   *  -  Layer
      -  Tool
      -  Runner
   *  -  Unit tests
      -  PHPUnit
      -  ``ddev composer ci:tests:unit`` or ``runTests.sh -s unit``
   *  -  Functional tests
      -  PHPUnit + TYPO3 testing framework
      -  ``ddev composer ci:tests`` (requires database)
   *  -  Architecture tests
      -  PHPAt (via PHPStan extension)
      -  ``ddev composer ci:phpstan`` (runs automatically with PHPStan)
   *  -  Static analysis
      -  PHPStan
      -  ``ddev composer ci:phpstan``
   *  -  Code style
      -  PHP-CS-Fixer
      -  ``ddev composer ci:cgl``
   *  -  Mutation testing
      -  Infection
      -  ``ddev composer ci:mutation``

Running tests with DDEV
=======================

..  code-block:: bash

    # Unit tests
    ddev composer ci:tests:unit

    # Unit + functional tests
    ddev composer ci:tests

    # Static analysis (includes architecture tests)
    ddev composer ci:phpstan

    # Mutation testing
    ddev composer ci:mutation

Running tests with Docker (runTests.sh)
=======================================

``Build/Scripts/runTests.sh`` provides a Docker-based test runner that
mirrors the CI environment exactly. It does not require DDEV.

..  code-block:: bash

    # Show all options
    ./Build/Scripts/runTests.sh -h

    # Unit tests
    ./Build/Scripts/runTests.sh -s unit

    # Unit tests with a specific PHP version
    ./Build/Scripts/runTests.sh -s unit -p 8.3

    # PHPStan
    ./Build/Scripts/runTests.sh -s phpstan

    # Code style check
    ./Build/Scripts/runTests.sh -s cgl

    # Fix code style
    ./Build/Scripts/runTests.sh -s cgl -n

    # Mutation testing
    ./Build/Scripts/runTests.sh -s mutation

Supported ``-s`` values: ``unit``, ``unitCoverage``, ``cgl``, ``phpstan``,
``rector``, ``mutation``, ``lint``, ``composer``, ``composerUpdate``,
``clean``, ``update``.

Architecture tests
==================

Architecture tests enforce dependency rules between the extension's layers.
They are implemented using `PHPAt <https://github.com/carlosas/phpat>`__
and registered as a PHPStan extension — they run automatically as part of
``ci:phpstan``, not as a separate PHPUnit testsuite.

The rules are defined in ``Tests/Architecture/LayerDependencyTest.php``.
They ensure, for example, that Domain classes do not depend on Controller
classes.

Mutation testing
================

`Infection <https://infection.github.io/>`__ is used to verify the
quality of unit tests by introducing code mutations and checking whether
tests catch them.

The minimum thresholds are defined in ``infection.json.dist``:

*   **minMsi**: 60 % (Mutation Score Indicator)
*   **minCoveredMsi**: 70 % (Covered Code MSI)

Run locally:

..  code-block:: bash

    ddev composer ci:mutation

Some mutations are intentionally ignored (see ``infection.json.dist``):

*   ``CastArray`` on ``GeneralUtility::makeInstance`` calls -- untestable
    in unit tests without TYPO3 boot.
*   Logical conditions on ``PHP_SAPI`` -- compile-time constant, always
    ``'cli'`` in unit test context.
