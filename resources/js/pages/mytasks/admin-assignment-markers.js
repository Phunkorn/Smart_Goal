const placeTaskSupport = () => {
    document.querySelectorAll('[data-task-support-source]').forEach((source) => {
        const task = source.nextElementSibling;
        if (!task || !source.content) return;

        const target = task.querySelector('.board-reference-task, .mytasks-kanban__open, .row-title');
        if (!target) return;

        const content = source.content.cloneNode(true);
        const directLink = content.querySelector('.task-direct-link');
        if (directLink && target.matches('button')) {
            directLink.remove();
            target.append(content);
            task.append(directLink);
        } else {
            target.append(content);
        }
        source.remove();
    });
};

placeTaskSupport();
