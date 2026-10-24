.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

=======
AI Chat
=======

..  image:: /Images/netresearch-underline.svg
    :alt:
    :width: 200px
    :class: no-border

:Extension key:
    nr_mcp_agent

:Package name:
    netresearch/nr-mcp-agent

:Version:
    |release|

:Language:
    en

:Author:
    Netresearch DTT GmbH

:License:
    This document is published under the
    `GPL-2.0-or-later <https://spdx.org/licenses/GPL-2.0-or-later>`__
    license.

:Rendered:
    |today|

----

AI Chat integrates a conversational AI assistant into the
TYPO3 backend. It runs on the agent runtime of `nr-llm
<https://github.com/netresearch/t3x-nr-llm>`__ and works
through the tools nr-llm registers: its builtin set, plus
any `Model Context Protocol (MCP)
<https://modelcontextprotocol.io/>`__ server configured
there. Backend users manage content through natural
language.

Getting started
===============

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :card-height: 100

    ..  card:: Installation

        Install the extension and its requirements.

        ..  card-footer:: :doc:`Installation guide <Installation/Index>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Configuration

        Configure backend access, tools, and worker operation.

        ..  card-footer:: :doc:`Configuration guide <Configuration/Index>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Usage

        Open the backend chat and review proposed actions.

        ..  card-footer:: :doc:`Usage guide <Usage/Index>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Developer information

        Read about the architecture, agent loop, and testing.

        ..  card-footer:: :doc:`Developer documentation <Developer/Index>`
            :button-style: btn btn-secondary stretched-link

----

..  toctree::
    :maxdepth: 2
    :titlesonly:

    Introduction/Index
    Installation/Index
    Configuration/Index
    Usage/Index
    Developer/Index
    Changelog

..  card:: [n] A Netresearch extension

    ..  image:: /Images/netresearch-underline.svg
        :alt:
        :width: 120px
        :class: no-border

    Developed and maintained by Netresearch DTT GmbH.

    ..  card-footer:: `netresearch.de <https://www.netresearch.de/>`__
        :button-style: btn btn-secondary stretched-link
