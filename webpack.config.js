const path = require("path");
const TerserPlugin = require("terser-webpack-plugin");

module.exports = {
  entry: "./src/js/nostr-login.js",
  output: {
    filename: "nostr-login.min.js",
    path: path.resolve(__dirname, "assets/js"),
  },
  mode: "production",
  optimization: {
    minimizer: [new TerserPlugin()],
  },
  resolve: {
    extensions: ['.js', '.jsx', '.ts', '.tsx'],
    modules: ['node_modules']
  }
};
