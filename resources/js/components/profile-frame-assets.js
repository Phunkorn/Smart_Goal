export default import.meta.glob(
    '../../images/profile-frames/*/*.{png,webp,gif,apng,svg}',
    {eager: true, query: '?url&no-inline', import: 'default'},
);
