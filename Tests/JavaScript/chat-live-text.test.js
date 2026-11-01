// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * A running turn over a stream (ADR-028): the text grows while the model
 * writes it, the end of the turn fetches the messages at once, and every way
 * the stream can fail leaves the chat polling. What a browser does with it
 * (focus, layout, the proxy) is not provable here.
 */

import {afterEach, beforeEach, describe, expect, jest, test} from '@jest/globals';
import {ChatCoreController} from '../../Resources/Public/JavaScript/chat-core.js';

class FakeEventSource {
    static instances = [];

    constructor(url) {
        this.url = url;
        this.closed = false;
        FakeEventSource.instances.push(this);
    }

    close() {
        this.closed = true;
    }

    emit(event) {
        this.onmessage({data: JSON.stringify(event)});
    }
}

function latest() {
    return FakeEventSource.instances[FakeEventSource.instances.length - 1];
}

function controller(pollAnswers = []) {
    const host = {
        addController() {},
        requestUpdate: jest.fn(),
        onScrollToBottom: jest.fn(),
        isConnected: true,
    };
    const chat = new ChatCoreController(host);
    const queue = [...pollAnswers];
    chat._api = {
        getMessages: jest.fn(async () => queue.shift() ?? {status: 'processing', messages: [], totalCount: 1}),
        streamUrl: (uid) => `/stream?conversationUid=${uid}`,
    };
    chat.activeUid = 1;
    chat.status = 'processing';

    return chat;
}

describe('the stream of a running turn', () => {
    beforeEach(() => {
        FakeEventSource.instances = [];
        globalThis.EventSource = FakeEventSource;
        jest.useFakeTimers();
    });

    afterEach(() => {
        delete globalThis.EventSource;
        jest.useRealTimers();
    });

    test('a turn opens a stream for its conversation', () => {
        controller().startPollingIfNeeded();

        expect(latest().url).toBe('/stream?conversationUid=1');
    });

    test('the text grows with each piece and scrolls with it', () => {
        const chat = controller();
        chat.startPollingIfNeeded();

        latest().emit({text: 'Hal'});
        latest().emit({append: 'lo'});

        expect(chat.liveText).toBe('Hallo');
        expect(chat.host.onScrollToBottom).toHaveBeenCalledTimes(2);
    });

    test('a reader who scrolled up stays where they are', () => {
        const chat = controller();
        chat.host.isAtBottom = () => false;
        chat.startPollingIfNeeded();

        latest().emit({text: 'Hal'});

        expect(chat.liveText).toBe('Hal');
        expect(chat.host.onScrollToBottom).not.toHaveBeenCalled();
    });

    test('a text that is no continuation replaces what is shown', () => {
        const chat = controller();
        chat.startPollingIfNeeded();

        latest().emit({text: 'erster Versuch'});
        latest().emit({text: 'zweiter'});

        expect(chat.liveText).toBe('zweiter');
    });

    test('the activity comes with the stream', () => {
        const chat = controller();
        chat.startPollingIfNeeded();

        latest().emit({activity: [{kind: 'llm', round: 1, ms: 900}]});

        expect(chat.activity).toEqual([{kind: 'llm', round: 1, ms: 900}]);
    });

    test('the end of the turn fetches the messages at once and drops the text', async () => {
        const chat = controller([{status: 'idle', messages: [{role: 'assistant', content: 'Hallo'}], totalCount: 2}]);
        chat._knownMessageCount = 1;
        chat.startPollingIfNeeded();
        const stream = latest();

        stream.emit({text: 'Hallo'});
        stream.emit({done: true});
        await jest.advanceTimersByTimeAsync(0);

        expect(chat._api.getMessages).toHaveBeenCalledTimes(1);
        expect(stream.closed).toBe(true);
        expect(chat.messages).toEqual([{role: 'assistant', content: 'Hallo'}]);
        expect(chat.liveText).toBe('');
        expect(chat.status).toBe('idle');
    });

    test('the end of a turn that goes on polling keeps the chat busy', async () => {
        const chat = controller();
        chat.startPollingIfNeeded();

        latest().emit({done: true});
        await jest.advanceTimersByTimeAsync(0);
        await jest.advanceTimersByTimeAsync(2000);

        expect(chat._api.getMessages).toHaveBeenCalledTimes(2);
    });

    test('beside an open stream the poll only stands guard', async () => {
        const chat = controller();
        chat.startPollingIfNeeded();

        await jest.advanceTimersByTimeAsync(9000);
        expect(chat._api.getMessages).not.toHaveBeenCalled();

        await jest.advanceTimersByTimeAsync(1000);
        expect(chat._api.getMessages).toHaveBeenCalledTimes(1);
    });

    test('a stream that is refused leaves the chat polling at its normal rate, without a new try', async () => {
        const chat = controller();
        chat.startPollingIfNeeded();

        latest().onerror();
        expect(latest().closed).toBe(true);
        expect(FakeEventSource.instances).toHaveLength(1);

        await jest.advanceTimersByTimeAsync(2000);
        expect(chat._api.getMessages).toHaveBeenCalledTimes(1);
    });

    test('a stream that breaks after it delivered is opened once more', () => {
        const chat = controller();
        chat.startPollingIfNeeded();

        latest().emit({text: 'Hal'});
        latest().onerror();

        expect(FakeEventSource.instances).toHaveLength(2);
        expect(FakeEventSource.instances[0].closed).toBe(true);
        expect(chat.liveText).toBe('Hal');
    });

    test('a stream at its time limit is replaced', () => {
        const chat = controller();
        chat.startPollingIfNeeded();
        const first = latest();

        first.emit({reconnect: true});

        expect(first.closed).toBe(true);
        expect(FakeEventSource.instances).toHaveLength(2);
    });

    test('leaving the conversation closes the stream and a late event changes nothing', () => {
        const chat = controller();
        chat.startPollingIfNeeded();
        const stream = latest();

        chat.stopPolling();
        stream.emit({text: 'zu spaet'});

        expect(stream.closed).toBe(true);
        expect(chat.liveText).toBe('');
    });

    test('a message inside a turn that goes on is fetched at once', async () => {
        const chat = controller();
        chat._knownMessageCount = 1;
        chat.startPollingIfNeeded();

        latest().emit({totalCount: 1});
        await jest.advanceTimersByTimeAsync(0);
        expect(chat._api.getMessages).not.toHaveBeenCalled();

        latest().emit({totalCount: 2});
        await jest.advanceTimersByTimeAsync(0);
        expect(chat._api.getMessages).toHaveBeenCalledTimes(1);
    });

    test('a poll that is on its way when the turn ends is not doubled', async () => {
        let answer;
        const chat = controller();
        chat._knownMessageCount = 1;
        chat._api.getMessages = jest.fn()
            .mockImplementationOnce(() => new Promise((resolve) => { answer = resolve; }))
            .mockResolvedValue({status: 'idle', messages: [{role: 'assistant', content: 'Hallo'}], totalCount: 2});
        chat.startPollingIfNeeded();
        await jest.advanceTimersByTimeAsync(10000);
        expect(chat._api.getMessages).toHaveBeenCalledTimes(1);

        latest().emit({done: true});
        await jest.advanceTimersByTimeAsync(0);
        expect(chat._api.getMessages).toHaveBeenCalledTimes(1);

        // That poll left before the end of the turn, so the one asked for runs.
        answer({status: 'processing', messages: [], totalCount: 1});
        await jest.advanceTimersByTimeAsync(0);

        expect(chat._api.getMessages).toHaveBeenCalledTimes(2);
        expect(chat.messages).toEqual([{role: 'assistant', content: 'Hallo'}]);
    });

    test('a poll of a stopped loop neither holds up the next nor counts', async () => {
        let answer;
        const chat = controller();
        chat._api.getMessages = jest.fn()
            .mockImplementationOnce(() => new Promise((resolve) => { answer = resolve; }))
            .mockResolvedValue({status: 'processing', messages: [], totalCount: 1});
        chat.pollMessages();
        chat.stopPolling();

        await chat.pollMessages();
        expect(chat._api.getMessages).toHaveBeenCalledTimes(2);

        answer({status: 'processing', messages: [{role: 'assistant', content: 'alt'}], totalCount: 2});
        await jest.advanceTimersByTimeAsync(0);
        expect(chat.messages).toEqual([]);
    });

    test('an event of the conversation just left changes nothing', () => {
        const chat = controller();
        chat.startPollingIfNeeded();
        const stream = latest();

        chat.activeUid = 2;
        stream.emit({text: 'fremd'});

        expect(chat.liveText).toBe('');
    });

    test('a browser without EventSource polls', async () => {
        delete globalThis.EventSource;
        const chat = controller();
        chat.startPollingIfNeeded();

        await jest.advanceTimersByTimeAsync(2000);

        expect(chat._api.getMessages).toHaveBeenCalledTimes(1);
    });

    test('without a stream the poll shows the text as it grows', async () => {
        delete globalThis.EventSource;
        const chat = controller([{status: 'processing', messages: [], totalCount: 1, liveText: 'Hal'}]);
        chat.startPollingIfNeeded();

        await jest.advanceTimersByTimeAsync(2000);

        expect(chat.liveText).toBe('Hal');
    });

    test('without a stream a reader who scrolled up stays there too', async () => {
        delete globalThis.EventSource;
        const chat = controller([{status: 'processing', messages: [], totalCount: 1, liveText: 'Hal'}]);
        chat.host.isAtBottom = () => false;
        chat.startPollingIfNeeded();

        await jest.advanceTimersByTimeAsync(2000);

        expect(chat.liveText).toBe('Hal');
        expect(chat.host.onScrollToBottom).not.toHaveBeenCalled();
    });

    test('a reload in the middle of an answer finds the text so far', async () => {
        const chat = controller();
        chat._api.getMessages = async () => ({status: 'locked', messages: [{role: 'user', content: 'Hallo'}], totalCount: 1, liveText: 'Guten'});

        await chat.loadMessages();

        expect(chat.liveText).toBe('Guten');
    });
});
