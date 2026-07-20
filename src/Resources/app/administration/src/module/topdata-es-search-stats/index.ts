import './page/search-stats-list';

Shopware.Module.register('topdata-es-search-stats', {
    type: 'plugin',
    name: 'SearchStats',
    title: 'TopdataElasticsearchHacksSW6.topdata-es-search-stats.title',
    description: 'TopdataElasticsearchHacksSW6.topdata-es-search-stats.description',
    color: '#189eff',
    icon: 'default-shopping-search',

    routes: {
        list: {
            component: 'topdata-es-search-stats-list',
            path: 'list',
            meta: {
                privilege: 'system.zero_search.viewer',
            },
        },
    },

    navigation: [{
        id: 'topdata-elasticsearch-hacks-sw6',
        label: 'TopdataElasticsearchHacksSW6.nav.mainTitle',
        color: '#189eff',
        icon: 'default-shopping-search',
        position: 100,
        parent: 'sw-content',
    }, {
        id: 'topdata-es-search-stats-list',
        label: 'TopdataElasticsearchHacksSW6.nav.searchStats',
        color: '#189eff',
        path: 'topdata.es.search.stats.list',
        parent: 'topdata-elasticsearch-hacks-sw6',
    }],
});
