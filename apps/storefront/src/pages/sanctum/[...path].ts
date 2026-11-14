import type { APIRoute } from 'astro';
import { forwardApi } from '../../server/proxy';
export const ALL: APIRoute = forwardApi;
