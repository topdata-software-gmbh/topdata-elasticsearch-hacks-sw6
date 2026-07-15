import './page/synonym-list';

Shopware.Module.register('topdata-es-synonym', {
    type: 'plugin',
    name: 'Synonyms',
    title: 'topdata-es-synonym.title',
    description: 'topdata-es-synonym.description',
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
        label: 'topdata-es-synonym.listTitle',
        color: '#189eff',
        path: 'topdata.es.synonym.list',
        parent: 'topdata-es-zero-search',
    }],
});
