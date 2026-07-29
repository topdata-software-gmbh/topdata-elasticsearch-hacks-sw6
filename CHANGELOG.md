# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.7.0] - 2026-07-28

### Added
- `SearchProviderInterface` — generic contract for tag-based search providers
- `ProductSearchProvider` — product search using `setTerm()` for full ES pipeline compatibility
- `CategorySearchProvider` — adapter over existing `CategorySearchService`
- `ManufacturerSearchProvider` — adapter over existing `ManufacturerSearchService`
- `SearchSuggestionProvider` — adapter over existing `SearchSuggestionService`
- All providers registered with `topdata_enhanced_search.search_provider` tag
