import './page/zero-search-list';

Shopware.Module.register('topdata-es-zero-search', {
    type: 'plugin',
    name: 'ZeroSearch',
    title: 'TopdataElasticsearchHacksSW6.topdata-es-zero-search.title',
    description: 'TopdataElasticsearchHacksSW6.topdata-es-zero-search.description',
    color: '#189eff',
    icon: 'default-shopping-search',

    routes: {
        list: {
            component: 'topdata-es-zero-search-list',
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
        id: 'topdata-es-zero-search-list',
        label: 'TopdataElasticsearchHacksSW6.nav.zeroSearchResults',
        color: '#189eff',
        path: 'topdata.es.zero.search.list',
        parent: 'topdata-elasticsearch-hacks-sw6',
    }],
});
