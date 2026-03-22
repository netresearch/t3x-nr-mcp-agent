..  include:: /Includes.rst.txt

============
Installation
============

Requirements
============

*   TYPO3 v13.4+ or v14.x
*   PHP 8.2+
*   `netresearch/nr-llm <https://github.com/netresearch/
    t3x-nr-llm>`__ (^0.5) -- LLM abstraction layer

Optional:

*   `hn/typo3-mcp-server <https://github.com/
    hauptsache-net/typo3-mcp-server>`__ (^1.0) --
    for TYPO3 content management tools
*   `netresearch/nr-vault <https://github.com/netresearch/
    t3x-nr-vault>`__ (^0.4) -- for secure API key storage

Quick start
===========

1.  Install the extension via Composer (see below).
2.  In nr-llm, create a **Task** record that configures
    your LLM provider (e.g. OpenAI, Anthropic). Note
    the UID.
3.  Go to **Admin Tools > Settings > Extension
    Configuration > nr_mcp_agent** and set ``llmTaskUid``
    to the Task UID from step 2.

The AI Chat module is now available under
**Admin Tools > AI Chat**.

Composer installation
=====================

..  code-block:: bash

    composer require netresearch/nr-mcp-agent

After installation, run the database migrations:

..  code-block:: bash

    vendor/bin/typo3 database:updateschema

To enable MCP integration (content management tools):

..  code-block:: bash

    composer require hn/typo3-mcp-server

Then set ``enableMcp = 1`` in the extension configuration.

DDEV development setup
======================

The project includes a DDEV configuration for local
development:

..  code-block:: bash

    git clone https://github.com/netresearch/t3x-nr-mcp-agent.git
    cd t3x-nr-mcp-agent
    ddev start
    ddev composer install
    ddev typo3 database:updateschema

The extension is symlinked into the TYPO3 installation
automatically via the Composer ``typo3/cms`` extra
configuration.

Running tests and quality checks:

..  code-block:: bash

    # All CI checks (PHPStan + CGL + tests)
    ddev composer ci

    # Individual checks
    ddev composer ci:phpstan     # Static analysis + architecture tests
    ddev composer ci:cgl         # Code style check
    ddev composer ci:tests:unit  # Unit tests only
    ddev composer ci:tests       # Unit + functional tests
    ddev composer ci:mutation    # Mutation testing (Infection)

    # Fix code style
    ddev composer fix:cgl

Alternatively, use the Docker-based test runner (works without DDEV):

..  code-block:: bash

    ./Build/Scripts/runTests.sh -s unit        # Unit tests
    ./Build/Scripts/runTests.sh -s phpstan     # PHPStan
    ./Build/Scripts/runTests.sh -s cgl         # Code style check
    ./Build/Scripts/runTests.sh -s mutation    # Mutation testing
    ./Build/Scripts/runTests.sh -s unit -p 8.3 # Specific PHP version
    ./Build/Scripts/runTests.sh -h             # Show all options
