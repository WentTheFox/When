<script setup lang="ts">
/**
 * The logo + app name pairing shared by SiteHeader.vue (reads them off
 * SharedPageProps) and Errors/Maintenance.vue (gets them as explicit props
 * instead — see that file's own doc comment for why: it renders before
 * HandleInertiaRequests ever runs, so SharedPageProps isn't available).
 * Split out purely so neither has to repeat the <Link>/<img>/sizing
 * markup — the two callers' data sources stay genuinely different.
 */
import { Link } from '@inertiajs/vue3';
import logoUrl from '../../img/When.svg';

withDefaults(defineProps<{ appName: string; href?: string }>(), { href: '/' });
</script>

<template>
  <Link class="navbar-brand" :href="href">
    <img :src="logoUrl" alt="" width="28" height="28" class="d-inline-block align-text-top me-2">
    {{ appName }}
  </Link>
</template>
