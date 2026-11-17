<?php

declare(strict_types=1);

return [
    'title' => 'Inbox',
    'description' => 'Comments and messages from your channels in one place.',
    'filters' => [
        'status' => 'Show',
        'open' => 'Inbox',
        'unread' => 'Unread',
        'archived' => 'Archived',
        'all' => 'All',
        'platform' => 'Network',
        'all_platforms' => 'All networks',
        'kind' => 'Type',
        'all_kinds' => 'Comments and messages',
    ],
    'kinds' => [
        'comment' => 'Comment',
        'message' => 'Message',
    ],
    'list' => [
        'empty_title' => 'Nothing here yet',
        'empty_body' => 'New comments and messages from your channels show up here a few minutes after they arrive.',
        'no_results' => 'No comments or messages match these filters. Try another network or type.',
        'unknown_author' => 'Someone',
        'linkedin_member' => 'LinkedIn member',
        'hidden' => 'Hidden',
    ],
    'thread' => [
        'select' => 'Select a comment or message to read the conversation.',
        'on_post' => 'On your post',
        'open_on_network' => 'Open on the network',
        'earlier' => 'Earlier in this conversation',
        'your_replies' => 'Your replies',
        'reply_failed' => 'Not sent',
        'reply_sending' => 'Sending',
    ],
    'reply' => [
        'placeholder' => 'Write a reply',
        'send' => 'Send reply',
        'window_ends' => 'You can reply until :time.',
        'window_closed' => 'The reply window for this conversation has closed. You can answer again once they write to you.',
        'not_allowed' => 'Replies to this item aren\'t possible from TryPost.',
        'no_permission' => 'Only members who publish directly can reply.',
    ],
    'actions' => [
        'mark_read' => 'Mark as read',
        'mark_unread' => 'Mark as unread',
        'archive' => 'Archive',
        'unarchive' => 'Move to inbox',
        'hide' => 'Hide comment',
    ],
    'channels' => [
        'title' => 'Channels',
        'needs_permission' => 'Needs permission',
        'statuses' => [
            'pending' => 'Waiting for the first check',
            'ok' => 'Up to date',
            'needs_permission' => 'Needs permission',
            'rate_limited' => 'Paused by the network\'s limit',
            'authentication' => 'Reconnect the channel',
            'unsupported' => 'Not available',
            'error' => 'Couldn\'t read',
        ],
    ],
    'errors' => [
        'window_closed' => 'The reply window for this conversation has closed.',
        'too_long' => 'Your reply is too long: :detail',
        'cannot_reply' => 'This reply can\'t be sent: :detail',
        'send_failed' => 'The network didn\'t accept the reply. Try again in a few minutes.',
        'hide_unavailable' => 'This comment can\'t be hidden from TryPost.',
        'hide_failed' => 'The network didn\'t hide the comment. Try again in a few minutes.',
    ],
];
