export function shouldMarkCommentsRead(source, tab) {
    return tab === 'updates' && (source === 'tab' || source === 'deep-link');
}

export function commentDeepLink(search) {
    const params = new URLSearchParams(search);
    return {
        taskId: params.get('open_task'),
        tab: params.get('task_tab'),
    };
}

export function prependComment(timeline, taskId, comment) {
    timeline[String(taskId)] ||= {updates: [], activity: []};
    timeline[String(taskId)].updates.unshift(comment);
    return timeline[String(taskId)].updates;
}

export function unreadCountAfterRead() {
    return 0;
}

export function canComposeComment(taskManagement) {
    return taskManagement?.can_comment === true && Boolean(taskManagement.comment_url);
}
