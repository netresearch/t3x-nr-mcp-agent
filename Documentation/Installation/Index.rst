.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

============
Installation
============

Requirements
============

*   TYPO3 v13.4 LTS or v14.3 LTS
*   PHP 8.2+
*   EXT:filelist (``typo3/cms-filelist``) -- the file picker
    uses its element browser
*   `netresearch/nr-llm <https://github.com/netresearch/
    t3x-nr-llm>`__ (^0.41.7) -- LLM abstraction layer

Optional:

*   `netresearch/nr-vault <https://github.com/netresearch/
    t3x-nr-vault>`__ (^0.14) -- for secure API key storage

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

Upgrading
=========

After updating the extension, run the schema update and the
upgrade wizards. A release that has to correct stored rows
ships a wizard and names it in the changelog:

..  code-block:: bash

    vendor/bin/typo3 database:updateschema
    vendor/bin/typo3 upgrade:list
    vendor/bin/typo3 upgrade:run

.. _installation-decorative-images:

Decorative images in your own templates
=======================================

An editor can mark an image as decorative (see
:ref:`usage-decorative-images`). The extension makes
fluid_styled_content output such an image with an empty ``alt``
attribute, so screen readers skip it. It does this with its own copy of
the partial ``Media/Rendering/Image``, at
``lib.contentElement.partialRootPaths.5``. That path is meant only for
sites whose content rendering is fluid_styled_content's. What to do
depends on how the site loads it:

-   **fluid_styled_content's static template** in a TypoScript record
    (``sys_template``, *Include static (from extensions)*): nothing to do.
    The path is added right after that static template.
-   **fluid_styled_content imported in TypoScript**
    (``@import 'EXT:fluid_styled_content/Configuration/TypoScript/setup.typoscript'``):
    import the extension's line right after it:

    ..  code-block:: typoscript

        @import 'EXT:fluid_styled_content/Configuration/TypoScript/setup.typoscript'
        @import 'EXT:nr_mcp_agent/Configuration/Sets/DecorativeImages/setup.typoscript'

-   **fluid_styled_content as a site set:** add the set *AI Assistant:
    decorative images in fluid_styled_content*
    (``netresearch/nr-mcp-agent-decorative-images``) to the site. It
    depends on ``typo3/fluid-styled-content`` and is loaded after it.
    Without the set the extension does not change the site's output.
-   **bootstrap_package or any other content rendering of its own:** do
    not add the set or the import. They would replace the site's own image
    partial for every image. Add the condition below to your own image
    partial instead.

A site that includes fluid_styled_content's static template and then a
content rendering of its own that redefines ``lib.contentElement``
without clearing it first (bootstrap_package 15 through its static
template is built like this) also gets the path, and its own image
partial is replaced. Remove the path after that rendering's TypoScript
and add the condition to its partial:

..  code-block:: typoscript

    lib.contentElement.partialRootPaths.5 >

A site package with its own image partial at a higher key (such as
``styles.templates.partialRootPath`` at 10), and the templates of other
extensions, for example a news detail view, are not changed. Add
the condition there yourself. ``f:media`` replaces an empty ``alt`` with
the file's alternative text, so render the decorative image with
``f:image``. Give it ``title=""`` as well: without it, ``f:image`` adds the
file's title, and a decorative image carries neither text (W3C technique
H67):

..  code-block:: html

    <f:if condition="{file.properties.tx_nrmcpagent_decorative}">
        <f:then>
            <f:image image="{file}" width="{dimensions.width}" height="{dimensions.height}" alt="" title="" />
        </f:then>
        <f:else>
            <f:media file="{file}" width="{dimensions.width}" height="{dimensions.height}" alt="{file.alternative}" title="{file.title}" />
        </f:else>
    </f:if>

``{file}`` is a ``TYPO3\CMS\Core\Resource\FileReference``. In an Extbase
template the image is usually an Extbase file reference: read the mark with
``{image.originalResource.properties.tx_nrmcpagent_decorative}`` and pass
``image="{image}"``.

After uninstalling the extension, its partial is no longer used, and
fluid_styled_content outputs a decorative image with its own alt text or,
without one, the file list's. Uninstalling does not drop the column
``tx_nrmcpagent_decorative``; the database analyzer in *Maintenance* removes
it and its values.

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
