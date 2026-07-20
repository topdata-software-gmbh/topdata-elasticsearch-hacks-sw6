import './page/search-log-list';

Shopware.Module.register('topdata-es-search-log', {
    type: 'plugin',
    name: 'SearchLog',
    title: 'TopdataElasticsearchHacksSW6.topdata-es-search-log.title',
    description: 'TopdataElasticsearchHacksSW6.topdata-es-search-log.description',
    color: '#189eff',
    icon: 'default-shopping-search',

    routes: {
        list: {
            component: 'topdata-es-search-log-list',
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
        id: 'topdata-es-search-log-list',
        label: 'TopdataElasticsearchHacksSW6.nav.searchLog',
        color: '#189eff',
        path: 'topdata.es.search.log.list',
        parent: 'topdata-elasticsearch-hacks-sw6',
    }],
});
