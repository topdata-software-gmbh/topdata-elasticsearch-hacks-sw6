import './page/suggestion-list';

Shopware.Module.register('topdata-es-suggestion', {
    type: 'plugin',
    name: 'SearchSuggestions',
    title: 'TopdataElasticsearchHacksSW6.topdata-es-suggestion.title',
    description: 'TopdataElasticsearchHacksSW6.topdata-es-suggestion.description',
    color: '#37d6b5',

    routes: {
        list: {
            component: 'topdata-es-suggestion-list',
            path: 'list',
            meta: {
                privilege: 'system.zero_search.viewer',
            },
        },
    },

    navigation: [{
        id: 'topdata-es-suggestion',
        parent: 'topdata-elasticsearch-hacks-sw6',
        label: 'TopdataElasticsearchHacksSW6.topdata-es-suggestion.title',
        path: 'topdata.es.suggestion.list',
        icon: 'default-shopping-search',
        privilege: 'system.zero_search.viewer',
    }],
});
