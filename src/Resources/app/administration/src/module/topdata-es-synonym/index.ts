import './page/synonym-list';

Shopware.Module.register('topdata-es-synonym', {
    type: 'plugin',
    name: 'Synonyms',
    title: 'TopdataElasticsearchHacksSW6.topdata-es-synonym.title',
    description: 'TopdataElasticsearchHacksSW6.topdata-es-synonym.description',
    color: '#189eff',
    icon: 'default-shopping-search',

    routes: {
        list: {
            component: 'topdata-es-synonym-list',
            path: 'list',
            meta: {
                privilege: 'system.zero_search.viewer',
            },
        },
    },

    navigation: [{
        id: 'topdata-es-synonym-list',
        label: 'TopdataElasticsearchHacksSW6.topdata-es-synonym.listTitle',
        color: '#189eff',
        path: 'topdata.es.synonym.list',
        parent: 'topdata-elasticsearch-hacks-sw6',
    }],
});
