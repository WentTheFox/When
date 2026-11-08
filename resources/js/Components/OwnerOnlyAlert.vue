<script setup lang="ts">
import { BAlert, BButton } from 'bootstrap-vue-next';
import { onMounted, ref } from 'vue';
import { PanelBottomOpen, PanelBottomClose, UserX, EyeIcon, EyeOff } from '@lucide/vue';

defineProps<{
  isOwnerPreview: boolean;
  hasOwnerMarker: boolean;
}>();

const ownerCustomizationsEnabled = defineModel('ownerCustomizationsEnabled');

const emit = defineEmits<{
  (e: 'clearMarker'): void;
}>();

const bodyRef = ref<HTMLBodyElement | null>(null);
const isCollapsed = ref<boolean>(false);

onMounted(() => {
  if (typeof document === 'undefined') return;

  bodyRef.value = document.body as HTMLBodyElement;
});
</script>

<template>
  <!-- Owner-only: recordVisit()'s POST only ever comes back 202
       (setting isOwnerPreview) when this *is* the owner viewing
       their own link. The toggle just flips
       ownerCustomizationsEnabled — every owner-only bit below
       (picker, locale note, refresh button, and the offset note's
       comparison target) reacts to that via showOwnerCustomizations. -->
  <Teleport v-if="bodyRef" :to="bodyRef">
    <div class="owner-alert">
      <BButton
        v-if="isCollapsed"
        variant="info"
        class="m-3 p-2 rounded-circle"
        @click="isCollapsed = false"
      >
        <PanelBottomOpen />
      </BButton>
      <BAlert
        v-if="isOwnerPreview && !isCollapsed"
        variant="info"
        class="rounded-0 mb-0"
        bodyClass="flex-wrap align-items-center justify-content-between"
        :visible="true"
        :no-fade="true"
      >
        <template #title><p class="h5">You're viewing this as
          <strong>{{ ownerCustomizationsEnabled ? 'the calendar owner' : 'a guest' }}</strong>.</p>
        </template>
        <p class="mb-2">
          <template v-if="ownerCustomizationsEnabled">This view displays additional information
            that's normally not shown to visitors of the link, such as recorded timezone and locale
            information.
          </template>
          <template v-else>This view shows only what signed-out visitors of the link would see.
          </template>
        </p>
        <p class="mb-2">An <strong>owner marker</strong> is automatically set when you sign in, and
          remembers you as the owner for your own shared calendars so your visits aren't recorded
          if you yourself open them (even while signed out). This can interfere with visitor info
          tracking data if you use multiple accounts simultaneously.</p>
        <div class="d-flex flex-wrap gap-2">
          <BButton variant="link" class="p-2" @click="isCollapsed = true">
            <PanelBottomClose />
          </BButton>
          <BButton
            variant="outline-secondary"
            @click="ownerCustomizationsEnabled = !ownerCustomizationsEnabled"
          >
            <template v-if="ownerCustomizationsEnabled">
              <EyeIcon />
              Preview as guest
            </template>
            <template v-else>
              <EyeOff />
              Show owner view
            </template>
          </BButton>
          <BButton
            v-if="hasOwnerMarker"
            variant="outline-danger"
            @click="emit('clearMarker')"
          >
            <UserX class="me-2" />
            Clear owner marker
          </BButton>
        </div>
      </BAlert>
    </div>
  </Teleport>
</template>

<style scoped>
.owner-alert {
  position: fixed;
  left: 0;
  bottom: 0;
  z-index: 10;
  max-width: 100%;
  width: 500px;
}
</style>
