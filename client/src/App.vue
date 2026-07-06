<script>
import { loadLocaleMessages } from './utils/i18n';

export default {
  onLaunch: function () {
    loadLocaleMessages().catch((error) => {
      console.error(error);
    });
  },
  onShow: function () {},
  onHide: function () {},
}
</script>

<style>
page {
  background: #f4f7fb;
  color: #243447;
  font-family: -apple-system, BlinkMacSystemFont, "Helvetica Neue", Arial, sans-serif;
}

button {
  border-radius: 8px;
}
</style>
