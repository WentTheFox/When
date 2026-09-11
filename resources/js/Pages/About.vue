<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CenteredColumn from '../Components/CenteredColumn.vue';
import SecurityCardBody from '../Components/SecurityCardBody.vue';
import WelcomeCardBody from '../Components/WelcomeCardBody.vue';
import PublicLayout from '../Layouts/PublicLayout.vue';

defineOptions({ layout: PublicLayout });
</script>

<template>
  <!--
    `title` shorthand, not a raw <title> child — the latter used to render
    page.props.appName directly, which duplicated the app.ts title callback's
    own " · When?" suffix into "When · When?" once that callback started
    applying to every page (see app.ts's own comment). "About" reads better
    here anyway and matches every other page's own descriptive-title
    convention (Settings, Dashboard, ...).
  -->
  <Head title="About">
    <meta
      name="description"
      content="Share your availability with friends for simpler event planning across timezones"
    >
  </Head>

  <CenteredColumn size="wide">
    <div class="card mb-4">
      <div class="card-body">
        <WelcomeCardBody />

        <div class="mb-3"><!-- spacing --></div>

        <SecurityCardBody />
      </div>
    </div>
  </CenteredColumn>
</template>
