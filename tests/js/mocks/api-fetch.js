/**
 * Jest mock for @wordpress/api-fetch (mapped in jest.config.js). Default: a request that never resolves.
 * In tests: `apiFetch.mockResolvedValueOnce( { … } )` or `apiFetch.mockImplementation( ( { url } ) => … )`.
 */
const apiFetch = jest.fn( () => new Promise( () => {} ) );
apiFetch.use = jest.fn();
apiFetch.createNonceMiddleware = jest.fn();
module.exports = apiFetch;
module.exports.default = apiFetch;
